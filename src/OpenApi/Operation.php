<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

/**
 * One OpenAPI operation, reduced to what the MCP bridge needs.
 *
 * @internal
 */
final readonly class Operation
{
    /**
     * @param non-empty-string $operationId
     * @param non-empty-string $method HTTP method, upper-case
     * @param non-empty-string $path path template with {param} placeholders
     * @param list<array{name: non-empty-string, in: 'path'|'query', required: bool, schema: array<array-key, mixed>, description: string}> $parameters
     * @param array<array-key, mixed>|null $requestBodySchema JSON schema of the application/json request body
     */
    public function __construct(
        public string $operationId,
        public string $method,
        public string $path,
        public string $description,
        public array $parameters,
        public ?array $requestBodySchema,
        public bool $requestBodyRequired,
    ) {}
}
