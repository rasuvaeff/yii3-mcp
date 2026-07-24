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
     * @param list<array{name: non-empty-string, in: 'path'|'query'|'header'|'cookie', required: bool, schema: array<array-key, mixed>, description: string, style: ?string, explode: ?bool, allowReserved: bool}> $parameters
     * @param array<array-key, mixed>|null $requestBodySchema JSON schema of the application/json request body
     * @param array{type: 'object', properties?: array<string, mixed>, required?: list<string>, additionalProperties?: array<string, mixed>|bool, description?: string}|null $outputSchema canonicalized object schema of the success response, advertised as the MCP tool outputSchema
     */
    public function __construct(
        public string $operationId,
        public string $method,
        public string $path,
        public string $description,
        public array $parameters,
        public ?array $requestBodySchema,
        public bool $requestBodyRequired,
        public ?array $outputSchema = null,
    ) {}
}
