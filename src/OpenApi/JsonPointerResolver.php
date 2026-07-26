<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;

/**
 * Inlines local `#/components/...` references with an explicit work budget:
 * the depth limit bounds a single `$ref` chain (circular references), the
 * node budget bounds the TOTAL number of nodes resolution may touch across
 * the document — a hostile or degenerate remote spec (deeply fanned-out
 * shared schemas) must not be able to make indexing allocate and recurse
 * without bound. External (URL/file) references pass through verbatim.
 *
 * Not readonly: the node budget is a counter shared across every resolution
 * this instance performs, deliberately — per-call budgets would multiply by
 * the number of operations.
 *
 * @internal
 */
final class JsonPointerResolver
{
    private const int MAX_REF_DEPTH = 32;
    private const int MAX_RESOLVED_NODES = 100_000;

    private int $resolvedNodes = 0;

    /**
     * @param array<string, mixed> $spec decoded OpenAPI document
     */
    public function __construct(
        private readonly array $spec,
    ) {}

    /**
     * Inlines local `#/components/...` references, recursively. The depth
     * limit counts `$ref` hops along a branch — plain array nesting without
     * references is unlimited (but still counts against the node budget).
     *
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    public function resolve(array $node, int $refDepth = 0): array
    {
        if (++$this->resolvedNodes > self::MAX_RESOLVED_NODES) {
            throw new InvalidSpecException(sprintf(
                'OpenAPI document exceeds the reference-resolution budget of %d nodes',
                self::MAX_RESOLVED_NODES,
            ));
        }

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
                $node[$key] = $this->resolve($value, $refDepth);
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

    private function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
