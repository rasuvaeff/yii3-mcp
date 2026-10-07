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

    /**
     * Legal serializations for an array-valued query parameter.
     */
    public const string ARRAY_STYLE_REPEAT = 'repeat';
    public const string ARRAY_STYLE_BRACKETS = 'brackets';
    public const string ARRAY_STYLE_COMMA = 'comma';
    private const list<string> ARRAY_STYLES = [self::ARRAY_STYLE_REPEAT, self::ARRAY_STYLE_BRACKETS, self::ARRAY_STYLE_COMMA];

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
     * @param ?string $arrayQueryStyle default serialization for array-valued query parameters:
     *                                 'repeat' (name=a&name=b, the OpenAPI form/explode default),
     *                                 'brackets' (name[]=a&name[]=b, what PHP's parse_str reads as an array)
     *                                 or 'comma' (name=a,b). Null derives it from the parameter's own
     *                                 declared explode (false -> comma, else repeat).
     * @param array<string, array<string, string>> $arrayQueryParams per-operation, per-parameter
     *                                 style overrides: ['getCreators' => ['platforms' => 'brackets']];
     *                                 wins over $arrayQueryStyle, which wins over the declared explode
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
        private ?string $arrayQueryStyle = null,
        private array $arrayQueryParams = [],
    ) {
        if ($this->arrayQueryStyle !== null && !in_array($this->arrayQueryStyle, self::ARRAY_STYLES, strict: true)) {
            throw new InvalidArgumentException(sprintf(
                'Array query style must be one of %s, "%s" given',
                implode(', ', self::ARRAY_STYLES),
                $this->arrayQueryStyle,
            ));
        }

        foreach ($this->arrayQueryParams as $operationId => $styles) {
            if (!is_array($styles)) {
                throw new InvalidArgumentException(sprintf(
                    'Array query style overrides for operation "%s" must be a parameter-name => style map',
                    is_string($operationId) ? $operationId : get_debug_type($operationId),
                ));
            }

            /** @var mixed $style */
            foreach ($styles as $parameterName => $style) {
                if (!is_string($style) || !in_array($style, self::ARRAY_STYLES, strict: true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Array query style for "%s.%s" must be one of %s, %s given',
                        is_string($operationId) ? $operationId : get_debug_type($operationId),
                        is_string($parameterName) ? $parameterName : get_debug_type($parameterName),
                        implode(', ', self::ARRAY_STYLES),
                        is_string($style) ? '"' . $style . '"' : get_debug_type($style),
                    ));
                }
            }
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
        $arrayPairs = [];

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
            } elseif (is_array($arguments[$name])) {
                // array-valued filter: serialized per the resolved style,
                // validated here so a direct-executor caller cannot smuggle
                // an oversized or non-scalar-items array past the SDK's own
                // input-schema validation
                $arrayPairs[] = $this->arrayQueryPairs($operation, $parameter, $arguments[$name], $served);
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

        $queryString = $query === [] ? '' : http_build_query($query);

        foreach ($arrayPairs as $pairs) {
            if ($pairs === '') {
                continue;
            }

            $queryString = $queryString === '' ? $pairs : $queryString . '&' . $pairs;
        }

        return $path . ($queryString === '' ? '' : '?' . $queryString);
    }

    /**
     * Serializes one array-valued query parameter into wire pairs, honouring
     * the resolved style: per-parameter override, then the configured
     * default, then the parameter's own declared explode.
     *
     * @param array{name: non-empty-string, in: 'path'|'query'|'header'|'cookie', required: bool, schema: array<array-key, mixed>, description: string, style: ?string, explode: ?bool, allowReserved: bool} $parameter
     * @param array<int, mixed> $values
     */
    private function arrayQueryPairs(Operation $operation, array $parameter, array $values, string $served): string
    {
        $schema = $parameter['schema'];
        /** @var mixed $maxItems */
        $maxItems = $schema['maxItems'] ?? null;
        /** @var mixed $minItems */
        $minItems = $schema['minItems'] ?? null;

        if (is_int($maxItems) && count($values) > $maxItems) {
            throw new InvalidToolArgumentException(sprintf(
                'Argument "%s" of tool "%s" must contain at most %d item%s',
                $parameter['name'],
                $served,
                $maxItems,
                $maxItems === 1 ? '' : 's',
            ));
        }

        if (is_int($minItems) && count($values) < $minItems) {
            throw new InvalidToolArgumentException(sprintf(
                'Argument "%s" of tool "%s" must contain at least %d item%s',
                $parameter['name'],
                $served,
                $minItems,
                $minItems === 1 ? '' : 's',
            ));
        }

        $stringified = [];

        /** @var mixed $value */
        foreach ($values as $value) {
            // the same scalar guard as single-valued arguments — an object
            // or nested array item has no query representation
            $stringified[] = rawurlencode($this->stringifyArgument($served, $parameter['name'], $value));
        }

        if ($stringified === []) {
            // an empty filter constrains nothing; minItems (if declared)
            // already rejected it above
            return '';
        }

        $name = rawurlencode($parameter['name']);

        return match ($this->arrayQueryStyle($operation, $parameter)) {
            self::ARRAY_STYLE_BRACKETS => implode('&', array_map(
                static fn(string $value): string => $name . '%5B%5D=' . $value,
                $stringified,
            )),
            self::ARRAY_STYLE_COMMA => $name . '=' . implode(',', $stringified),
            default => implode('&', array_map(
                static fn(string $value): string => $name . '=' . $value,
                $stringified,
            )),
        };
    }

    /**
     * @param array{name: non-empty-string, in: 'path'|'query'|'header'|'cookie', required: bool, schema: array<array-key, mixed>, description: string, style: ?string, explode: ?bool, allowReserved: bool} $parameter
     */
    private function arrayQueryStyle(Operation $operation, array $parameter): string
    {
        /** @var mixed $override */
        $override = $this->arrayQueryParams[$operation->operationId][$parameter['name']] ?? null;

        if (is_string($override)) {
            return $override;
        }

        if ($this->arrayQueryStyle !== null) {
            return $this->arrayQueryStyle;
        }

        // declared serialization: form + explode=false is comma-separated;
        // the OpenAPI default (explode=true) repeats the key
        return $parameter['explode'] === false ? self::ARRAY_STYLE_COMMA : self::ARRAY_STYLE_REPEAT;
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
