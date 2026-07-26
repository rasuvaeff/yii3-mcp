<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\UnknownOperationException;

/**
 * Indexes a decoded OpenAPI 3.x document by operationId. Composed of
 * focused collaborators so trust and resource decisions live in one place
 * each: {@see JsonPointerResolver} inlines local `#/components/...`
 * references under an explicit depth + node budget (external URL/file
 * `$ref`s pass through verbatim), {@see OutputSchemaProjector} derives the
 * MCP `outputSchema` from the success response, and
 * {@see OperationContractValidator} asserts at build time that the
 * parameters fit what the HTTP executor can serialize. The document itself
 * is size-bounded before decoding. Operations without an operationId cannot
 * be bridged and are skipped.
 *
 * @internal
 */
final readonly class SpecIndex
{
    private const array HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * Upper bound on the raw OpenAPI document accepted for decoding — a
     * remote spec endpoint must not be able to make indexing buffer an
     * arbitrarily large body.
     */
    public const int MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

    /**
     * @var array<string, Operation>
     */
    private array $operations;

    private OperationContractValidator $contract;

    /**
     * @param array<string, mixed> $spec decoded OpenAPI document
     */
    public function __construct(array $spec)
    {
        $paths = $spec['paths'] ?? null;

        if (!is_array($paths) || $paths === []) {
            throw new InvalidSpecException('OpenAPI document has no paths');
        }

        $resolver = new JsonPointerResolver($spec);
        $projector = new OutputSchemaProjector($resolver);
        $this->contract = new OperationContractValidator();

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

                $operation = $this->buildOperation(path: $path, method: $method, raw: $raw, pathItem: $pathItem, resolver: $resolver, projector: $projector);

                if ($operation instanceof Operation) {
                    if (isset($operations[$operation->operationId])) {
                        $previous = $operations[$operation->operationId];

                        throw new InvalidSpecException(sprintf(
                            'Duplicate operationId "%s" for %s %s and %s %s',
                            $operation->operationId,
                            $previous->method,
                            $previous->path,
                            $operation->method,
                            $operation->path,
                        ));
                    }

                    $operations[$operation->operationId] = $operation;
                }
            }
        }

        $this->operations = $operations;
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAX_DOCUMENT_BYTES) {
            throw new InvalidSpecException(sprintf(
                'OpenAPI document of %d bytes exceeds the %d-byte limit',
                strlen($json),
                self::MAX_DOCUMENT_BYTES,
            ));
        }

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
        // bound before buffering: a runaway generated spec file must fail
        // with a clear message, not exhaust the worker
        $size = @filesize($path);

        if (is_int($size) && $size > self::MAX_DOCUMENT_BYTES) {
            throw new InvalidSpecException(sprintf(
                'OpenAPI document "%s" of %d bytes exceeds the %d-byte limit',
                $path,
                $size,
                self::MAX_DOCUMENT_BYTES,
            ));
        }

        $json = @file_get_contents($path);

        if ($json === false) {
            throw new InvalidSpecException(sprintf('OpenAPI document "%s" is not readable', $path));
        }

        return self::fromJson($json);
    }

    public function get(string $operationId): Operation
    {
        $operation = $this->operations[$operationId]
            ?? throw new UnknownOperationException(sprintf(
                'Operation "%s" is not present in the OpenAPI document; known operations: %s',
                $operationId,
                implode(', ', array_keys($this->operations)),
            ));

        $this->assertValidToolName($operation);
        $this->contract->validate($operation);

        return $operation;
    }

    private function assertValidToolName(Operation $operation): void
    {
        if (!ToolNameValidator::isValid($operation->operationId)) {
            throw new InvalidSpecException(sprintf(
                'Operation "%s" (%s %s) has an operationId that cannot be used as an MCP tool name',
                $operation->operationId,
                $operation->method,
                $operation->path,
            ));
        }
    }

    /**
     * @param non-empty-string $method
     * @param array<array-key, mixed> $raw
     * @param array<array-key, mixed> $pathItem
     */
    private function buildOperation(string $path, string $method, array $raw, array $pathItem, JsonPointerResolver $resolver, OutputSchemaProjector $projector): ?Operation
    {
        $operationId = $raw['operationId'] ?? null;

        if (!is_string($operationId) || $operationId === '' || $path === '') {
            return null;
        }

        // The OpenAPI spec requires every Path Item Object key to start with
        // "/"; enforcing that here is not an extra restriction, it rejects a
        // non-conformant document. It also closes a host-splicing hazard:
        // HttpOperationExecutor concatenates baseUrl . path with no
        // separator, so a path lacking the leading slash (e.g. "evil.com/x")
        // would turn "https://api.test" into "https://api.testevil.com/x" —
        // a different host entirely.
        if (!str_starts_with($path, '/')) {
            return null;
        }

        $requestBody = $this->arrayOrEmpty($raw['requestBody'] ?? null);

        if ($requestBody !== []) {
            $requestBody = $resolver->resolve($requestBody);
        }

        return new Operation(
            operationId: $operationId,
            method: strtoupper($method),
            path: $path,
            description: $this->stringOrEmpty($raw['description'] ?? $raw['summary'] ?? null),
            parameters: $this->normalizeParameters($this->arrayOrEmpty($pathItem['parameters'] ?? null), $this->arrayOrEmpty($raw['parameters'] ?? null), $resolver),
            requestBodySchema: $this->extractRequestBodySchema($requestBody, $resolver),
            requestBodyRequired: (bool) ($requestBody['required'] ?? false),
            outputSchema: $projector->project($this->arrayOrEmpty($raw['responses'] ?? null)),
            tags: $this->extractTags($raw['tags'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    private function extractTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }

        $named = [];

        /** @var mixed $tag */
        foreach ($tags as $tag) {
            if (is_string($tag) && $tag !== '') {
                $named[] = $tag;
            }
        }

        return $named;
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
     * @return list<array{name: non-empty-string, in: 'path'|'query'|'header'|'cookie', required: bool, schema: array<array-key, mixed>, description: string, style: ?string, explode: ?bool, allowReserved: bool}>
     */
    private function normalizeParameters(array $pathLevel, array $operationLevel, JsonPointerResolver $resolver): array
    {
        $normalized = [];
        /** @var mixed $raw */
        foreach ([...$pathLevel, ...$operationLevel] as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $raw = $resolver->resolve($raw);
            $name = $raw['name'] ?? null;
            $in = $raw['in'] ?? null;
            /** @var mixed $rawStyle */
            $rawStyle = $raw['style'] ?? null;
            /** @var mixed $rawExplode */
            $rawExplode = $raw['explode'] ?? null;

            if (!is_string($name) || $name === '' || !in_array($in, ['path', 'query', 'header', 'cookie'], strict: true)) {
                continue;
            }

            // operation-level parameters override path-level ones with the same name+in
            $normalized[$in . ':' . $name] = [
                'name' => $name,
                'in' => $in,
                'required' => $in === 'path' || (bool) ($raw['required'] ?? false),
                'schema' => $resolver->resolve($this->arrayOrEmpty($raw['schema'] ?? null)),
                'description' => $this->stringOrEmpty($raw['description'] ?? null),
                'style' => is_string($rawStyle) ? $rawStyle : null,
                'explode' => is_bool($rawExplode) ? $rawExplode : null,
                'allowReserved' => ($raw['allowReserved'] ?? false) === true,
            ];
        }

        return array_values($normalized);
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return array<array-key, mixed>|null
     */
    private function extractRequestBodySchema(array $body, JsonPointerResolver $resolver): ?array
    {
        if ($body === []) {
            return null;
        }

        $content = $this->arrayOrEmpty($body['content'] ?? null);
        $json = $this->arrayOrEmpty($content['application/json'] ?? null);
        $schema = $this->arrayOrEmpty($json['schema'] ?? null);

        return $schema === [] ? null : $resolver->resolve($schema);
    }
}
