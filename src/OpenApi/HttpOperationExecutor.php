<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\OperationFailedException;
use Rasuvaeff\Yii3Mcp\Utf8;

/**
 * Executes a bridged operation as a real HTTP call against the upstream
 * REST API — the request passes the application's full middleware stack
 * (validation, rate limiting, auth), unlike direct handler invocation.
 *
 * @internal
 */
final readonly class HttpOperationExecutor
{
    private const int MAX_ERROR_BODY_LENGTH = 2_000;

    private string $baseUrl;

    /**
     * @param array<string, string> $defaultHeaders e.g. ['Authorization' => 'Bearer …']
     */
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        string $baseUrl,
        private array $defaultHeaders = [],
        private ?ExecutionIdentityProviderInterface $identityProvider = null,
        private ?DelegatedHeaderProviderInterface $delegatedHeaderProvider = null,
    ) {
        $normalized = rtrim(trim($baseUrl), '/');

        if ($normalized === '') {
            throw new InvalidArgumentException('Base URL must not be empty');
        }

        $parts = parse_url($normalized);

        if (is_array($parts)) {
            // dry-run previews return the full URL to the caller, so the
            // base URL must never be a credential carrier; parse_url sets
            // "user" (possibly empty) whenever a userinfo section exists,
            // so this single check covers user:pass and :pass forms alike
            if (isset($parts['user'])) {
                throw new InvalidArgumentException('Base URL must not embed credentials; pass them via default or delegated headers');
            }

            if (isset($parts['query']) || isset($parts['fragment'])) {
                throw new InvalidArgumentException('Base URL must not contain a query string or fragment');
            }
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
            return json_encode([
                'dryRun' => true,
                'operationId' => $operation->operationId,
                'method' => $operation->method,
                'url' => $this->baseUrl . $path,
                // mirror the real-send condition below: a bodyless operation
                // ignores a stray `body` argument, so the preview must not
                // claim it would be sent
                'body' => $operation->requestBodySchema !== null
                    ? ($arguments[InputSchemaBuilder::BODY_ARGUMENT] ?? null)
                    : null,
            ], JSON_THROW_ON_ERROR);
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
        $body = (string) $response->getBody();

        if ($response->getStatusCode() >= 300) {
            throw new OperationFailedException(sprintf(
                'Operation "%s" failed with HTTP %d: %s',
                $operation->operationId,
                $response->getStatusCode(),
                $this->errorExcerpt($body),
            ));
        }

        try {
            return json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $body;
        }
    }

    /**
     * The excerpt travels to the client inside a tool-error envelope the SDK
     * encodes with JSON_THROW_ON_ERROR, so it must be valid UTF-8: cutting
     * mid-character (Utf8::cut prevents that) or an upstream body that was
     * never UTF-8 in the first place (an HTML error page in a legacy
     * encoding, a binary payload) would make the whole response unencodable —
     * silently dropped on the Streamable HTTP transport.
     */
    private function errorExcerpt(string $body): string
    {
        $excerpt = Utf8::cut($body, self::MAX_ERROR_BODY_LENGTH);

        if (preg_match('//u', $excerpt) !== 1) {
            return sprintf('<non-UTF-8 response body, %d bytes>', strlen($body));
        }

        return strlen($body) > strlen($excerpt) ? $excerpt . '…' : $excerpt;
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
                // rawurlencode keeps "." verbatim, so a "." or ".." value
                // would survive into the upstream path and let a caller
                // climb out of the allow-listed route on servers that
                // normalize dot segments — with the bridge's credentials.
                // An empty value is the same escape one level up: "/users/"
                // is typically the collection route, not the allow-listed
                // item route
                if ($value === '' || $value === '.' || $value === '..') {
                    throw new InvalidArgumentException(sprintf(
                        'Argument "%s" of operation "%s" must not be empty or a dot segment',
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
