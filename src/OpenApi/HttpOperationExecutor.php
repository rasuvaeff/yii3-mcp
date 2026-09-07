<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\OperationFailedException;
use Rasuvaeff\Yii3Mcp\Utf8;

/**
 * Executes a bridged operation as a real HTTP call against the upstream
 * REST API — the request passes the application's full middleware stack
 * (validation, rate limiting, auth), unlike direct handler invocation.
 *
 * The response body is read INCREMENTALLY up to `$maxResponseBytes` and the
 * call fails before a byte over the cap is buffered — an advertised size
 * (Content-Length/stream size) over the cap is rejected without reading at
 * all. `ResponseSizeLimitInterceptor` bounds what reaches the agent's
 * context window, but it runs after the executor returns; this cap is what
 * protects the worker's memory from an unbounded upstream body.
 *
 * @internal
 */
final readonly class HttpOperationExecutor
{
    private const int MAX_ERROR_BODY_LENGTH = 2_000;

    /**
     * Materialized upstream response cap, mirrors the SDK transport's own
     * request-body default.
     */
    public const int DEFAULT_MAX_RESPONSE_BYTES = 4 * 1024 * 1024;

    /**
     * Nesting cap for decoding the upstream JSON body — far above any sane
     * API payload, far below the depth where recursive decoding hurts.
     */
    private const int JSON_MAX_DEPTH = 128;

    /**
     * Hard ceiling on an opted-in path argument's segment count. Deep enough
     * for the deepest nesting a real upstream expresses in one identifier
     * (GitLab caps subgroup nesting at 20), shallow enough that an operator
     * cannot turn the opt-in into "any depth" by writing a large number.
     */
    private const int MAX_PATH_SEGMENTS = 20;

    /**
     * Charset every segment of an opted-in multi-segment path argument must
     * match — anchored with \A/\z, never $, which matches before a trailing
     * newline. Deliberately narrower than the single-segment rule: opting a
     * parameter in trades an unrestricted charset for the extra separator.
     */
    private const string PATH_SEGMENT_PATTERN = '/\A[A-Za-z0-9_][A-Za-z0-9_.-]*\z/';

    private string $baseUrl;

    /**
     * Narrowed from the raw constructor argument: the value comes from
     * application params, where nothing enforces the shape.
     *
     * @var array<array-key, int<1, 20>>
     */
    private array $multiSegmentPathParams;

    /**
     * @param array<string, string> $defaultHeaders e.g. ['Authorization' => 'Bearer …']
     * @param int $maxResponseBytes upper bound on the upstream response body this executor will buffer
     * @param bool $opaqueErrors suppress the upstream error-body excerpt in failures — for
     *                           service-token deployments where the upstream's error details
     *                           are not the MCP caller's to see
     * @param array<array-key, mixed> $multiSegmentPathParams path parameter name => how many
     *                           "/"-separated segments its values may carry. Absent (the
     *                           default for every parameter) means one segment, i.e. no
     *                           separator at all — see {@see self::buildPath()}. Values are
     *                           validated here rather than typed: this arrives from params,
     *                           so a string "1" must fail loudly instead of enabling the
     *                           multi-segment path with a limit no comparison treats as one
     */
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        string $baseUrl,
        private array $defaultHeaders = [],
        private ?ExecutionIdentityProviderInterface $identityProvider = null,
        private ?DelegatedHeaderProviderInterface $delegatedHeaderProvider = null,
        private int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
        private bool $opaqueErrors = false,
        array $multiSegmentPathParams = [],
    ) {
        if ($maxResponseBytes < 1) {
            throw new InvalidArgumentException(sprintf('Max response bytes must be at least 1, %d given', $maxResponseBytes));
        }

        $segmentLimits = [];

        foreach ($multiSegmentPathParams as $parameterName => $maxSegments) {
            // is_int first and strictly: a numeric string passes every
            // comparison below, then fails === 1 in buildPath() — silently
            // turning the multi-segment path on with a limit that is not one
            if (!is_int($maxSegments) || $maxSegments < 1 || $maxSegments > self::MAX_PATH_SEGMENTS) {
                throw new InvalidArgumentException(sprintf(
                    'Segment limit for path parameter "%s" must be an integer between 1 and %d, %s given',
                    $parameterName,
                    self::MAX_PATH_SEGMENTS,
                    is_int($maxSegments) ? $maxSegments : get_debug_type($maxSegments),
                ));
            }

            $segmentLimits[$parameterName] = $maxSegments;
        }

        $this->multiSegmentPathParams = $segmentLimits;

        $normalized = rtrim(trim($baseUrl), '/');

        if ($normalized === '') {
            throw new InvalidArgumentException('Base URL must not be empty');
        }

        $parts = parse_url($normalized);

        // an unparseable URL must fail closed, not silently skip the guards
        // below — parse_url is lenient and rarely returns false, but a guard
        // that disables itself on the failure path is fragile by construction
        if (!is_array($parts)) {
            throw new InvalidArgumentException('Base URL is not a valid URL');
        }

        // dry-run previews return the full URL to the caller, so the base
        // URL must never be a credential carrier; parse_url sets "user"
        // (possibly empty) whenever a userinfo section exists, so this
        // single check covers user:pass and :pass forms alike
        if (isset($parts['user'])) {
            throw new InvalidArgumentException('Base URL must not embed credentials; pass them via default or delegated headers');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Base URL must not contain a query string or fragment');
        }

        if ((!$identityProvider instanceof ExecutionIdentityProviderInterface) !== (!$delegatedHeaderProvider instanceof DelegatedHeaderProviderInterface)) {
            throw new InvalidArgumentException('Execution identity provider and delegated header provider must be configured together');
        }

        $this->baseUrl = $normalized;
    }

    /**
     * @param array<string, mixed> $arguments tool arguments keyed by parameter name
     * @param ?string $toolName the name this operation is SERVED under (after
     *                          `tool_names` and the operation modifier); null
     *                          falls back to the operationId, which is correct
     *                          only when the operation was never renamed
     */
    public function execute(Operation $operation, array $arguments, bool $dryRunnable = false, ?string $toolName = null): mixed
    {
        // everything the CALLER reads names the tool it actually called: a
        // renamed operation's operationId is exactly what the rename hid
        // from the client, so quoting it back gives an agent a
        // plausible-looking identifier that is in no tool list it has
        $served = $toolName ?? $operation->operationId;

        // a malformed flag must not silently fall through to a REAL call —
        // that is the dangerous direction for a write operation the caller
        // intended to preview. The SDK's schema validation rejects
        // non-boolean values first; this guard keeps the failure mode safe
        // even when the executor is reached directly
        if ($dryRunnable
            && array_key_exists(InputSchemaBuilder::DRY_RUN_ARGUMENT, $arguments)
            && !is_bool($arguments[InputSchemaBuilder::DRY_RUN_ARGUMENT])
        ) {
            throw new InvalidArgumentException(sprintf(
                'Argument "%s" of tool "%s" must be a boolean',
                InputSchemaBuilder::DRY_RUN_ARGUMENT,
                $served,
            ));
        }

        $path = $this->buildPath($operation, $arguments, $served);

        if ($dryRunnable && ($arguments[InputSchemaBuilder::DRY_RUN_ARGUMENT] ?? false) === true) {
            // mirror the real-send condition below EXACTLY, including key
            // presence, not just value: `?? null` cannot tell "no body
            // argument" (real call sends nothing) apart from "body argument
            // explicitly null" (real call still sends Content-Type +
            // literal JSON "null"). Omitting the "body" field entirely
            // when no body would be sent — rather than showing it as null
            // either way — makes the preview distinguish the two
            $preview = [
                'dryRun' => true,
                'operationId' => $operation->operationId,
                'method' => $operation->method,
                'url' => $this->baseUrl . $path,
            ];

            if ($operation->requestBodySchema !== null && array_key_exists(InputSchemaBuilder::BODY_ARGUMENT, $arguments)) {
                /** @var mixed */
                $preview['body'] = $arguments[InputSchemaBuilder::BODY_ARGUMENT];
            }

            return json_encode($preview, JSON_THROW_ON_ERROR);
        }

        $request = $this->requestFactory->createRequest($operation->method, $this->baseUrl . $path);

        $headers = $this->defaultHeaders;

        if ($this->identityProvider instanceof ExecutionIdentityProviderInterface && $this->delegatedHeaderProvider instanceof DelegatedHeaderProviderInterface) {
            $headers = array_replace(
                $headers,
                $this->delegatedHeaderProvider->headers(
                    operationId: $operation->operationId,
                    method: $operation->method,
                    path: $operation->path,
                    identity: $this->identityProvider->current(),
                ),
            );
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $request = $request->withHeader('Accept', 'application/json');

        if ($operation->requestBodySchema !== null && array_key_exists(InputSchemaBuilder::BODY_ARGUMENT, $arguments)) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(
                    json_encode($arguments[InputSchemaBuilder::BODY_ARGUMENT], JSON_THROW_ON_ERROR),
                ));
        }

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() >= 300) {
            if ($this->opaqueErrors) {
                throw new OperationFailedException(sprintf(
                    'Tool "%s" failed with HTTP %d',
                    $served,
                    $response->getStatusCode(),
                ));
            }

            // the error path only ever needs the excerpt, so its read is
            // capped at excerpt size — an unbounded error page cannot
            // buffer. One byte over the excerpt length, so Utf8::cut sees an
            // over-limit body and trims a split trailing character instead
            // of passing it through untouched
            [$body, $truncated] = $this->readUpTo($response->getBody(), self::MAX_ERROR_BODY_LENGTH + 1, keepPrefix: true);

            throw new OperationFailedException(sprintf(
                'Tool "%s" failed with HTTP %d: %s',
                $served,
                $response->getStatusCode(),
                $this->errorExcerpt($body, $truncated),
            ));
        }

        [$body, $truncated] = $this->readUpTo($response->getBody(), $this->maxResponseBytes, keepPrefix: false);

        if ($truncated) {
            throw new OperationFailedException(sprintf(
                'Tool "%s" response exceeds the %d-byte limit; refusing to buffer it',
                $served,
                $this->maxResponseBytes,
            ));
        }

        try {
            return json_decode($body, associative: true, depth: self::JSON_MAX_DEPTH, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $body;
        }
    }

    /**
     * Incremental bounded read: an advertised size over the cap is rejected
     * before a single byte is read; an unadvertised (chunked) body is read in
     * chunks and abandoned the moment it exceeds the cap. The boolean tells
     * the caller whether the body was cut short. With $keepPrefix the caller
     * wants the first $cap bytes of an oversized body (the error excerpt), so
     * the advertised size is not an early-out.
     *
     * @return array{string, bool}
     */
    private function readUpTo(StreamInterface $stream, int $cap, bool $keepPrefix): array
    {
        // unlike a (string) cast, read() starts at the CURRENT position — a
        // seekable body that was already consumed (or created at EOF) must be
        // rewound or it reads as empty
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        if (!$keepPrefix) {
            $size = $stream->getSize();

            if ($size !== null && $size > $cap) {
                return ['', true];
            }
        }

        $contents = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;

            if (strlen($contents) > $cap) {
                return [substr($contents, 0, $cap), true];
            }
        }

        return [$contents, false];
    }

    /**
     * The excerpt travels to the client inside a tool-error envelope the SDK
     * encodes with JSON_THROW_ON_ERROR, so it must be valid UTF-8: cutting
     * mid-character (Utf8::cut prevents that) or an upstream body that was
     * never UTF-8 in the first place (an HTML error page in a legacy
     * encoding, a binary payload) would make the whole response unencodable —
     * silently dropped on the Streamable HTTP transport.
     *
     * @param bool $bodyTruncated whether the bounded read already cut the body short
     */
    private function errorExcerpt(string $body, bool $bodyTruncated): string
    {
        $excerpt = Utf8::cut($body, self::MAX_ERROR_BODY_LENGTH);

        if (preg_match('//u', $excerpt) !== 1) {
            return sprintf('<non-UTF-8 response body, %s%d bytes>', $bodyTruncated ? 'over ' : '', strlen($body));
        }

        return $bodyTruncated || strlen($body) > strlen($excerpt) ? $excerpt . '…' : $excerpt;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function buildPath(Operation $operation, array $arguments, string $served): string
    {
        $path = $operation->path;
        $query = [];

        foreach ($operation->parameters as $parameter) {
            $name = $parameter['name'];

            // null is treated the same as "not passed" — OpenAPI optional
            // scalars are nullable in the 3.1 union notation, and there is
            // no REST distinction between an absent and a null query/path value
            if (!array_key_exists($name, $arguments) || $arguments[$name] === null) {
                continue;
            }

            $value = $this->stringifyArgument($served, $name, $arguments[$name]);

            if ($parameter['in'] === 'path') {
                // one segment unless the operator opted this parameter in
                $maxSegments = $this->multiSegmentPathParams[$name] ?? 1;

                // rawurlencode keeps "." verbatim and turns "/" into "%2F",
                // which upstreams that decode before normalizing the path
                // (Apache with AllowEncodedSlashes, some proxies and servlet
                // containers) hand back as a real separator — so a value
                // merely CONTAINING ".." can climb out of the allow-listed
                // route with the bridge's credentials, not only a value equal
                // to a dot segment. An empty value is the same escape one
                // level up: "/users/" is typically the collection route, not
                // the allow-listed item route. ".." and a backslash stay
                // rejected whatever the limit is — the opt-in below buys the
                // separator and nothing else
                if ($value === ''
                    || $value === '.'
                    || str_contains($value, '..')
                    || str_contains($value, '\\')
                    || ($maxSegments === 1 && str_contains($value, '/'))
                ) {
                    throw new InvalidArgumentException(sprintf(
                        'Argument "%s" of tool "%s" must not be empty, a dot segment, or contain ".." or a path separator',
                        $name,
                        $served,
                    ));
                }

                // An opted-in value may cross segment boundaries, so the
                // allow-listed route no longer pins the URL's shape: a value
                // like "1/repository/archive" reaches an operation the
                // allow-list never exposed. The segment CAP is what bounds
                // that, and the charset keeps every segment a plain
                // identifier — neither is derivable from the value itself,
                // which is why both are fixed here and only the depth is
                // configurable
                if ($maxSegments > 1) {
                    $segments = explode('/', $value);

                    if (count($segments) > $maxSegments) {
                        throw new InvalidArgumentException($this->segmentRuleViolation($served, $name, $maxSegments));
                    }

                    foreach ($segments as $segment) {
                        if (preg_match(self::PATH_SEGMENT_PATTERN, $segment) !== 1) {
                            throw new InvalidArgumentException($this->segmentRuleViolation($served, $name, $maxSegments));
                        }
                    }
                }

                $path = str_replace('{' . $name . '}', rawurlencode($value), $path);
            } else {
                $query[$name] = $value;
            }
        }

        if (preg_match('/\{[^}]+\}/', $path) === 1) {
            throw new InvalidArgumentException(sprintf(
                'Tool "%s" is missing a required path parameter (path template: %s)',
                $served,
                $operation->path,
            ));
        }

        return $path . ($query === [] ? '' : '?' . http_build_query($query));
    }

    private function segmentRuleViolation(string $served, string $name, int $maxSegments): string
    {
        return sprintf(
            'Argument "%s" of tool "%s" must be 1 to %d "/"-separated segments, each starting with a letter, digit or "_" and containing only letters, digits, "_", "-" and "."',
            $name,
            $served,
            $maxSegments,
        );
    }

    private function stringifyArgument(string $served, string $name, mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            default => throw new InvalidArgumentException(sprintf(
                'Argument "%s" of tool "%s" must be a scalar',
                $name,
                $served,
            )),
        };
    }
}
