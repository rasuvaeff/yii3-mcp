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

    private string $baseUrl;

    /**
     * @param array<string, string> $defaultHeaders e.g. ['Authorization' => 'Bearer …']
     * @param int $maxResponseBytes upper bound on the upstream response body this executor will buffer
     * @param bool $opaqueErrors suppress the upstream error-body excerpt in failures — for
     *                           service-token deployments where the upstream's error details
     *                           are not the MCP caller's to see
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
    ) {
        if ($maxResponseBytes < 1) {
            throw new InvalidArgumentException(sprintf('Max response bytes must be at least 1, %d given', $maxResponseBytes));
        }

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
     */
    public function execute(Operation $operation, array $arguments, bool $dryRunnable = false): mixed
    {
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
                'Argument "%s" of operation "%s" must be a boolean',
                InputSchemaBuilder::DRY_RUN_ARGUMENT,
                $operation->operationId,
            ));
        }

        $path = $this->buildPath($operation, $arguments);

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
                    'Operation "%s" failed with HTTP %d',
                    $operation->operationId,
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
                'Operation "%s" failed with HTTP %d: %s',
                $operation->operationId,
                $response->getStatusCode(),
                $this->errorExcerpt($body, $truncated),
            ));
        }

        [$body, $truncated] = $this->readUpTo($response->getBody(), $this->maxResponseBytes, keepPrefix: false);

        if ($truncated) {
            throw new OperationFailedException(sprintf(
                'Operation "%s" response exceeds the %d-byte limit; refusing to buffer it',
                $operation->operationId,
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
    private function buildPath(Operation $operation, array $arguments): string
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

            $value = $this->stringifyArgument($operation, $name, $arguments[$name]);

            if ($parameter['in'] === 'path') {
                // rawurlencode keeps "." verbatim and turns "/" into "%2F",
                // which upstreams that decode before normalizing the path
                // (Apache with AllowEncodedSlashes, some proxies and servlet
                // containers) hand back as a real separator — so a value
                // merely CONTAINING ".." or a separator can climb out of the
                // allow-listed route with the bridge's credentials, not only
                // a value equal to a dot segment. An empty value is the same
                // escape one level up: "/users/" is typically the collection
                // route, not the allow-listed item route
                if ($value === ''
                    || $value === '.'
                    || str_contains($value, '..')
                    || str_contains($value, '/')
                    || str_contains($value, '\\')
                ) {
                    throw new InvalidArgumentException(sprintf(
                        'Argument "%s" of operation "%s" must not be empty, a dot segment, or contain ".." or a path separator',
                        $name,
                        $operation->operationId,
                    ));
                }

                $path = str_replace('{' . $name . '}', rawurlencode($value), $path);
            } else {
                $query[$name] = $value;
            }
        }

        if (preg_match('/\{[^}]+\}/', $path) === 1) {
            throw new InvalidArgumentException(sprintf(
                'Operation "%s" is missing a required path parameter (path template: %s)',
                $operation->operationId,
                $operation->path,
            ));
        }

        return $path . ($query === [] ? '' : '?' . http_build_query($query));
    }

    private function stringifyArgument(Operation $operation, string $name, mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            default => throw new InvalidArgumentException(sprintf(
                'Argument "%s" of operation "%s" must be a scalar',
                $name,
                $operation->operationId,
            )),
        };
    }
}
