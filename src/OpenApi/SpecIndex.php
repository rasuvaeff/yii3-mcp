<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\UnknownOperationException;

/**
 * Indexes a decoded OpenAPI 3.x document by operationId. Local
 * `#/components/...` schema references are resolved inline (chains of up
 * to 32 `$ref` hops); external references (URL or file `$ref`s) are not
 * resolved and pass through verbatim into the generated input schema.
 * Operations without an operationId cannot be bridged and are skipped.
 *
 * @internal
 */
final readonly class SpecIndex
{
    private const array HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];
    private const int MAX_REF_DEPTH = 32;

    /**
     * @var array<string, Operation>
     */
    private array $operations;

    /**
     * @param array<string, mixed> $spec decoded OpenAPI document
     */
    public function __construct(
        private array $spec,
    ) {
        $paths = $this->spec['paths'] ?? null;

        if (!is_array($paths) || $paths === []) {
            throw new InvalidSpecException('OpenAPI document has no paths');
        }

        $operations = [];
        /** @var mixed $pathItem */
        foreach ($paths as $path => $pathItem) {
            if (!is_string($path) || !is_array($pathItem)) {
                continue;
            }

            foreach (self::HTTP_METHODS as $method) {
                $raw = $pathItem[$method] ?? null;

                if (!is_array($raw)) {
                    continue;
                }

                $operation = $this->buildOperation(path: $path, method: $method, raw: $raw, pathItem: $pathItem);

                if ($operation instanceof Operation) {
                    $operations[$operation->operationId] = $operation;
                }
            }
        }

        $this->operations = $operations;
    }

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidSpecException('OpenAPI document is not valid JSON', $e->getCode(), previous: $e);
        }

        if (!is_array($decoded)) {
            throw new InvalidSpecException('OpenAPI document must decode to an object');
        }

        /** @var array<string, mixed> $decoded */
        return new self($decoded);
    }

    public static function fromFile(string $path): self
    {
        $json = @file_get_contents($path);

        if ($json === false) {
            throw new InvalidSpecException(sprintf('OpenAPI document "%s" is not readable', $path));
        }

        return self::fromJson($json);
    }

    public function get(string $operationId): Operation
    {
        return $this->operations[$operationId]
            ?? throw new UnknownOperationException(sprintf(
                'Operation "%s" is not present in the OpenAPI document; known operations: %s',
                $operationId,
                implode(', ', array_keys($this->operations)),
            ));
    }

    /**
     * @param non-empty-string $method
     * @param array<array-key, mixed> $raw
     * @param array<array-key, mixed> $pathItem
     */
    private function buildOperation(string $path, string $method, array $raw, array $pathItem): ?Operation
    {
        $operationId = $raw['operationId'] ?? null;

        if (!is_string($operationId) || $operationId === '' || $path === '') {
            return null;
        }

        $requestBody = $this->arrayOrEmpty($raw['requestBody'] ?? null);

        return new Operation(
            operationId: $operationId,
            method: strtoupper($method),
            path: $path,
            description: $this->stringOrEmpty($raw['description'] ?? $raw['summary'] ?? null),
            parameters: $this->normalizeParameters($this->arrayOrEmpty($pathItem['parameters'] ?? null), $this->arrayOrEmpty($raw['parameters'] ?? null)),
            requestBodySchema: $this->extractRequestBodySchema($raw),
            requestBodyRequired: (bool) ($requestBody['required'] ?? false),
        );
    }

    private function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<array-key, mixed> $pathLevel
     * @param array<array-key, mixed> $operationLevel
     *
     * @return list<array{name: non-empty-string, in: 'path'|'query', required: bool, schema: array<array-key, mixed>, description: string}>
     */
    private function normalizeParameters(array $pathLevel, array $operationLevel): array
    {
        $normalized = [];
        /** @var mixed $raw */
        foreach ([...$pathLevel, ...$operationLevel] as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $raw = $this->resolveRef($raw);
            $name = $raw['name'] ?? null;
            $in = $raw['in'] ?? null;

            if (!is_string($name) || $name === '' || ($in !== 'path' && $in !== 'query')) {
                continue;
            }

            // operation-level parameters override path-level ones with the same name+in
            $normalized[$in . ':' . $name] = [
                'name' => $name,
                'in' => $in,
                'required' => $in === 'path' || (bool) ($raw['required'] ?? false),
                'schema' => $this->resolveRef($this->arrayOrEmpty($raw['schema'] ?? null)),
                'description' => $this->stringOrEmpty($raw['description'] ?? null),
            ];
        }

        return array_values($normalized);
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array<array-key, mixed>|null
     */
    private function extractRequestBodySchema(array $raw): ?array
    {
        $body = $raw['requestBody'] ?? null;

        if (!is_array($body)) {
            return null;
        }

        $body = $this->resolveRef($body);
        $content = $this->arrayOrEmpty($body['content'] ?? null);
        $json = $this->arrayOrEmpty($content['application/json'] ?? null);
        $schema = $this->arrayOrEmpty($json['schema'] ?? null);

        return $schema === [] ? null : $this->resolveRef($schema);
    }

    /**
     * Inlines local `#/components/...` references, recursively. The depth
     * limit counts `$ref` hops along a branch — plain array nesting without
     * references is unlimited.
     *
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private function resolveRef(array $node, int $refDepth = 0): array
    {
        while (str_starts_with($this->stringOrEmpty($node['$ref'] ?? null), '#/')) {
            if (++$refDepth > self::MAX_REF_DEPTH) {
                throw new InvalidSpecException('OpenAPI $ref chain is too deep (possible circular reference)');
            }

            $ref = $this->stringOrEmpty($node['$ref'] ?? null);
            $resolved = $this->lookupPointer($ref);
            unset($node['$ref']);
            $node = [...$resolved, ...$node];
        }

        /** @var mixed $value */
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->resolveRef($value, $refDepth);
            }
        }

        return $node;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function lookupPointer(string $ref): array
    {
        $segments = explode('/', substr($ref, 2));
        $node = $this->spec;

        foreach ($segments as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (!array_key_exists($segment, $node)) {
                throw new InvalidSpecException(sprintf('Unresolvable $ref "%s" in OpenAPI document', $ref));
            }

            if (!is_array($node[$segment])) {
                throw new InvalidSpecException(sprintf('$ref "%s" must point to an object', $ref));
            }

            $node = $node[$segment];
        }

        return $node;
    }
}
