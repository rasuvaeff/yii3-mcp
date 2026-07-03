<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\OperationFailedException;

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
    ) {
        $normalized = rtrim(trim($baseUrl), '/');

        if ($normalized === '') {
            throw new InvalidArgumentException('Base URL must not be empty');
        }

        $this->baseUrl = $normalized;
    }

    /**
     * @param array<string, mixed> $arguments tool arguments keyed by parameter name
     */
    public function execute(Operation $operation, array $arguments): mixed
    {
        $request = $this->requestFactory->createRequest(
            $operation->method,
            $this->baseUrl . $this->buildPath($operation, $arguments),
        );

        foreach ($this->defaultHeaders as $name => $value) {
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
                strlen($body) > self::MAX_ERROR_BODY_LENGTH
                    ? substr($body, 0, self::MAX_ERROR_BODY_LENGTH) . '…'
                    : $body,
            ));
        }

        try {
            return json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $body;
        }
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

            if (!array_key_exists($name, $arguments)) {
                continue;
            }

            $value = $this->stringifyArgument($operation, $name, $arguments[$name]);

            if ($parameter['in'] === 'path') {
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
