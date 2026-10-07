<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use InvalidArgumentException;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidToolArgumentException;

/**
 * Assembles a bridged operation call into a {@see PlannedOperationRequest}:
 * argument validation, path substitution with every path-escape guard,
 * header assembly (configured defaults, delegated headers, Accept /
 * Content-Type) and the JSON body. Shared by both executors so the HTTP and
 * in-process transports behave identically apart from the send itself.
 *
 * @internal
 */
final readonly class OperationRequestBuilder
{
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
     * @param array<array-key, mixed> $multiSegmentPathParams path parameter name => how many
     *                           "/"-separated segments its values may carry. Absent (the
     *                           default for every parameter) means one segment, i.e. no
     *                           separator at all — see {@see self::buildPath()}. Values are
     *                           validated here rather than typed: this arrives from params,
     *                           so a string "1" must fail loudly instead of enabling the
     *                           multi-segment path with a limit no comparison treats as one
     */
    public function __construct(
        string $baseUrl,
        private array $defaultHeaders = [],
        private ?ExecutionIdentityProviderInterface $identityProvider = null,
        private ?DelegatedHeaderProviderInterface $delegatedHeaderProvider = null,
        array $multiSegmentPathParams = [],
    ) {
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

        if ((!$this->identityProvider instanceof ExecutionIdentityProviderInterface) !== (!$this->delegatedHeaderProvider instanceof DelegatedHeaderProviderInterface)) {
            throw new InvalidArgumentException('Execution identity provider and delegated header provider must be configured together');
        }

        $this->baseUrl = $normalized;
    }

    /**
     * A malformed dry-run flag must not silently fall through to a REAL call —
     * that is the dangerous direction for a write operation the caller
     * intended to preview. The SDK's schema validation rejects non-boolean
     * values first; this guard keeps the failure mode safe even when the
     * executor is reached directly.
     *
     * @param array<string, mixed> $arguments
     */
    public function assertDryRunFlag(array $arguments, bool $dryRunnable, string $served): void
    {
        if ($dryRunnable
            && array_key_exists(InputSchemaBuilder::DRY_RUN_ARGUMENT, $arguments)
            && !is_bool($arguments[InputSchemaBuilder::DRY_RUN_ARGUMENT])
        ) {
            throw new InvalidToolArgumentException(sprintf(
                'Argument "%s" of tool "%s" must be a boolean',
                InputSchemaBuilder::DRY_RUN_ARGUMENT,
                $served,
            ));
        }
    }

    /**
     * The dry-run preview: the planned request instead of executing it. Path
     * arguments are validated exactly like a real call — a preview of a
     * rejected argument is the rejection, not a bypass.
     *
     * Mirrors the real-send condition EXACTLY, including key presence, not
     * just value: `?? null` cannot tell "no body argument" (real call sends
     * nothing) apart from "body argument explicitly null" (real call still
     * sends Content-Type + literal JSON "null"). Omitting the "body" field
     * entirely when no body would be sent — rather than showing it as null
     * either way — makes the preview distinguish the two.
     *
     * @param array<string, mixed> $arguments
     */
    public function dryRunPreview(Operation $operation, array $arguments, string $served): string
    {
        $preview = [
            'dryRun' => true,
            'operationId' => $operation->operationId,
            'method' => $operation->method,
            'url' => $this->baseUrl . $this->buildPath($operation, $arguments, $served),
        ];

        if ($operation->requestBodySchema !== null && array_key_exists(InputSchemaBuilder::BODY_ARGUMENT, $arguments)) {
            /** @var mixed */
            $preview['body'] = $arguments[InputSchemaBuilder::BODY_ARGUMENT];
        }

        return json_encode($preview, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $arguments tool arguments keyed by parameter name
     */
    public function plan(Operation $operation, array $arguments, string $served): PlannedOperationRequest
    {
        $path = $this->buildPath($operation, $arguments, $served);

        $headers = $this->defaultHeaders;
        $identity = null;

        if ($this->identityProvider instanceof ExecutionIdentityProviderInterface && $this->delegatedHeaderProvider instanceof DelegatedHeaderProviderInterface) {
            // resolved ONCE per call: the plan's headers and the executor's
            // request attributes must derive from the same resolution, and a
            // provider that reads request state need not be idempotent
            $identity = $this->identityProvider->current();
            $headers = array_replace(
                $headers,
                $this->delegatedHeaderProvider->headers(
                    operationId: $operation->operationId,
                    method: $operation->method,
                    path: $operation->path,
                    identity: $identity,
                ),
            );
        }

        $headers['Accept'] = 'application/json';

        $body = null;

        if ($operation->requestBodySchema !== null && array_key_exists(InputSchemaBuilder::BODY_ARGUMENT, $arguments)) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($arguments[InputSchemaBuilder::BODY_ARGUMENT], JSON_THROW_ON_ERROR);
        }

        return new PlannedOperationRequest(
            method: $operation->method,
            url: $this->baseUrl . $path,
            headers: $headers,
            body: $body,
            identity: $identity,
        );
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
                    throw new InvalidToolArgumentException(sprintf(
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
                        throw new InvalidToolArgumentException($this->segmentRuleViolation($served, $name, $maxSegments));
                    }

                    foreach ($segments as $segment) {
                        if (preg_match(self::PATH_SEGMENT_PATTERN, $segment) !== 1) {
                            throw new InvalidToolArgumentException($this->segmentRuleViolation($served, $name, $maxSegments));
                        }
                    }
                }

                $path = str_replace('{' . $name . '}', rawurlencode($value), $path);
            } else {
                $query[$name] = $value;
            }
        }

        if (preg_match('/\{[^}]+\}/', $path) === 1) {
            throw new InvalidToolArgumentException(sprintf(
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
            default => throw new InvalidToolArgumentException(sprintf(
                'Argument "%s" of tool "%s" must be a scalar',
                $name,
                $served,
            )),
        };
    }
}
