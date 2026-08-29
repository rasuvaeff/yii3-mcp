<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;
use Rasuvaeff\Yii3Mcp\OpenApi\JsonPointerResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The resolver's whole reason to exist is bounding work on a document it does
 * not trust, so its properties are about termination and limits — a hostile
 * spec must always end in an {@see InvalidSpecException}, never in unbounded
 * recursion. `timeoutMs` is mandatory here: without a deadline a regression
 * in the depth guard hangs CI instead of failing it.
 */
#[Test]
#[Covers(JsonPointerResolver::class)]
final class JsonPointerResolverTest
{
    private const int MAX_REF_DEPTH = 32;

    /**
     * A `$ref` chain resolves exactly up to the documented depth and throws
     * beyond it — the boundary is a single `>` in the guard, so a property
     * that only tried short chains would never see it move.
     */
    #[Property(runs: 200, timeoutMs: 1000)]
    public function aRefChainResolvesUpToTheDepthLimitAndThrowsBeyondIt(int $length): void
    {
        $resolver = new JsonPointerResolver($this->chainSpec($length));
        $entry = ['$ref' => '#/components/schemas/s0'];

        Classify::cover($length <= self::MAX_REF_DEPTH, 'within the depth limit', 20.0);
        Classify::cover($length > self::MAX_REF_DEPTH, 'over the depth limit', 20.0);

        if ($length > self::MAX_REF_DEPTH) {
            Assert::true($this->rejects(static fn(): array => $resolver->resolve($entry)), 'chain over the limit resolved');

            return;
        }

        Assert::same($resolver->resolve($entry), ['type' => 'string']);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function aRefChainResolvesUpToTheDepthLimitAndThrowsBeyondItGenerators(): array
    {
        return ['length' => Gen::intBetween(1, 64)];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function aRefChainResolvesUpToTheDepthLimitAndThrowsBeyondItExamples(): iterable
    {
        yield 'single hop' => [1];
        yield 'one below the limit' => [self::MAX_REF_DEPTH - 1];
        yield 'exactly at the limit' => [self::MAX_REF_DEPTH];
        yield 'one over the limit' => [self::MAX_REF_DEPTH + 1];
    }

    /**
     * A circular chain of any shape terminates with a spec error. This is the
     * property the deadline guards: before the depth counter existed, this
     * input recursed until the process died.
     */
    #[Property(runs: 200, timeoutMs: 1000)]
    public function aCircularRefAlwaysTerminatesWithASpecError(int $size, int $entryPoint): void
    {
        $schemas = [];

        for ($index = 0; $index < $size; $index++) {
            $schemas['s' . $index] = ['$ref' => sprintf('#/components/schemas/s%d', ($index + 1) % $size)];
        }

        $resolver = new JsonPointerResolver(['components' => ['schemas' => $schemas]]);

        Classify::when($size === 1, 'self-reference');

        $entry = ['$ref' => sprintf('#/components/schemas/s%d', $entryPoint % $size)];

        Assert::true($this->rejects(static fn(): array => $resolver->resolve($entry)), 'a cycle resolved instead of failing');
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function aCircularRefAlwaysTerminatesWithASpecErrorGenerators(): array
    {
        return [
            'size' => Gen::intBetween(1, 12),
            'entryPoint' => Gen::intBetween(0, 100),
        ];
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function aCircularRefAlwaysTerminatesWithASpecErrorExamples(): iterable
    {
        yield 'self-reference' => [1, 0];
        yield 'two-node cycle' => [2, 0];
        yield 'entered mid-cycle' => [5, 3];
    }

    /**
     * A document with no local `$ref` must come back byte-identical: the
     * resolver inlines references, it does not normalize schemas. External
     * refs are part of that guarantee — they pass through verbatim.
     *
     * @param array<array-key, mixed> $node
     */
    #[Property(runs: 300, timeoutMs: 1000)]
    public function aDocumentWithoutLocalRefsIsReturnedUnchanged(array $node): void
    {
        Classify::when($node === [], 'empty node');

        Assert::same((new JsonPointerResolver([]))->resolve($node), $node);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function aDocumentWithoutLocalRefsIsReturnedUnchangedGenerators(): array
    {
        $leaf = Gen::oneOf('string', 42, true, null, 1.5, '');

        return [
            'node' => Gen::dictOf(
                Gen::elements(['type', 'format', 'items', 'properties', '$ref', 'description']),
                Gen::recursive(
                    Gen::frequency([
                        [3, $leaf],
                        // an external reference is a plain value to this
                        // resolver: only "#/" prefixed refs are inlined
                        [1, Gen::elements(['https://example.test/spec.yaml#/X', 'other.yaml#/Y', 'not-a-ref'])],
                    ]),
                    static fn(ArbitraryInterface $inner): ArbitraryInterface => Gen::dictOf(
                        Gen::elements(['type', 'items', 'nested']),
                        $inner,
                        0,
                        3,
                    ),
                    maxDepth: 3,
                ),
                0,
                4,
            ),
        ];
    }

    /**
     * Sibling keys next to a `$ref` win over the resolved target — the spec
     * calls those overrides, and dropping them silently changes a schema.
     */
    #[Property(runs: 200, timeoutMs: 1000)]
    public function siblingKeysOverrideTheResolvedTarget(string $description): void
    {
        $resolver = new JsonPointerResolver([
            'components' => ['schemas' => ['s' => ['type' => 'string', 'description' => 'from the target']]],
        ]);

        $resolved = $resolver->resolve(['$ref' => '#/components/schemas/s', 'description' => $description]);

        Assert::same($resolved, ['type' => 'string', 'description' => $description]);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function siblingKeysOverrideTheResolvedTargetGenerators(): array
    {
        return ['description' => Gen::stringAscii()];
    }

    /**
     * A `$ref` pointing at something that is not there, or not an object,
     * fails closed rather than resolving to an empty schema.
     */
    #[Property(runs: 200, timeoutMs: 1000)]
    public function anUnresolvableRefThrows(string $pointer): void
    {
        $resolver = new JsonPointerResolver([
            'components' => ['schemas' => ['s' => ['type' => 'string'], 'scalar' => 'not an object']],
        ]);

        $entry = ['$ref' => '#/' . $pointer];

        Assert::true($this->rejects(static fn(): array => $resolver->resolve($entry)), 'an unresolvable $ref was accepted');
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function anUnresolvableRefThrowsGenerators(): array
    {
        return [
            'pointer' => Gen::frequency([
                [2, Gen::map(
                    Gen::stringFrom('abz09', 1, 8),
                    static fn(string $name): string => 'components/schemas/' . $name . 'X',
                )],
                [1, Gen::elements([
                    'components/schemas/scalar',
                    'components/schemas/s/type',
                    'components/missing/s',
                    'missing',
                ])],
            ]),
        ];
    }

    /**
     * `Expect::exception()` declares an expectation for the whole test method,
     * which cannot express "throws on some runs, resolves on others" — so a
     * property checking a boundary catches the exception itself.
     *
     * @param callable(): array<array-key, mixed> $call
     */
    private function rejects(callable $call): bool
    {
        try {
            $call();
        } catch (InvalidSpecException) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function chainSpec(int $length): array
    {
        $schemas = [];

        for ($index = 0; $index < $length - 1; $index++) {
            $schemas['s' . $index] = ['$ref' => sprintf('#/components/schemas/s%d', $index + 1)];
        }

        $schemas['s' . ($length - 1)] = ['type' => 'string'];

        return ['components' => ['schemas' => $schemas]];
    }
}
