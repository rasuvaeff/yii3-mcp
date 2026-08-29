<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Mcp\Interceptor\ArgumentMasker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ArgumentMasker::class)]
final class ArgumentMaskerTest
{
    /**
     * The sensitive keys the generated payloads draw from, lowercased. A
     * subset of the class's own default list — the property needs a pool
     * small enough that generated keys actually collide with it, not a copy
     * of the list it is testing.
     */
    private const array SENSITIVE = ['password', 'token', 'api_key', 'authorization', 'secret'];

    #[DataProvider('defaultKeysProvider')]
    public function masksEveryDefaultKey(string $key): void
    {
        Assert::same((new ArgumentMasker())->mask([$key => 'value']), [$key => '***']);
    }

    public static function defaultKeysProvider(): iterable
    {
        yield 'password' => ['password'];
        yield 'pass' => ['pass'];
        yield 'pwd' => ['pwd'];
        yield 'secret' => ['secret'];
        yield 'token' => ['token'];
        yield 'auth' => ['auth'];
        yield 'bearer' => ['bearer'];
        yield 'Bearer' => ['Bearer'];
        yield 'jwt' => ['jwt'];
        yield 'JWT' => ['JWT'];
        yield 'cookie' => ['cookie'];
        yield 'Cookie' => ['Cookie'];
        yield 'api_key' => ['api_key'];
        yield 'apikey' => ['apikey'];
        yield 'ApiKey' => ['ApiKey'];
        yield 'api-key' => ['api-key'];
        yield 'x-api-key' => ['x-api-key'];
        yield 'X-Api-Key' => ['X-Api-Key'];
        yield 'access_token' => ['access_token'];
        yield 'accessToken' => ['accessToken'];
        yield 'access-token' => ['access-token'];
        yield 'id_token' => ['id_token'];
        yield 'idToken' => ['idToken'];
        yield 'session_token' => ['session_token'];
        yield 'sessionToken' => ['sessionToken'];
        yield 'auth_token' => ['auth_token'];
        yield 'authToken' => ['authToken'];
        yield 'refresh_token' => ['refresh_token'];
        yield 'refreshToken' => ['refreshToken'];
        yield 'client_secret' => ['client_secret'];
        yield 'clientSecret' => ['clientSecret'];
        yield 'private_key' => ['private_key'];
        yield 'privateKey' => ['privateKey'];
        yield 'Authorization' => ['Authorization'];
        yield 'credit_card' => ['credit_card'];
    }

    public function matchesKeysCaseInsensitively(): void
    {
        $masked = (new ArgumentMasker())->mask(['Password' => 'p', 'TOKEN' => 't']);

        Assert::same($masked, ['Password' => '***', 'TOKEN' => '***']);
    }

    public function masksAtEveryNestingLevel(): void
    {
        $masked = (new ArgumentMasker())->mask([
            'user' => [
                'name' => 'alice',
                'password' => 'p@ss',
                'credentials' => ['token' => 'abc', 'scope' => 'read'],
            ],
        ]);

        Assert::same($masked, [
            'user' => [
                'name' => 'alice',
                'password' => '***',
                'credentials' => ['token' => '***', 'scope' => 'read'],
            ],
        ]);
    }

    public function masksInsideListsOfObjects(): void
    {
        $masked = (new ArgumentMasker())->mask([
            'accounts' => [
                ['login' => 'a', 'password' => '1'],
                ['login' => 'b', 'password' => '2'],
            ],
        ]);

        Assert::same($masked, [
            'accounts' => [
                ['login' => 'a', 'password' => '***'],
                ['login' => 'b', 'password' => '***'],
            ],
        ]);
    }

    public function replacesWholeArrayValueOfSensitiveKey(): void
    {
        $masked = (new ArgumentMasker())->mask(['secret' => ['inner' => 'x']]);

        Assert::same($masked, ['secret' => '***']);
    }

    public function passesNonSensitiveValuesThroughUntouched(): void
    {
        $arguments = ['name' => 'alice', 'age' => 42, 'active' => true, 'ratio' => 0.5, 'none' => null];

        Assert::same((new ArgumentMasker())->mask($arguments), $arguments);
    }

    public function doesNotMaskPartialKeyMatches(): void
    {
        $arguments = ['tokenizer' => 'utf8', 'passwords_enabled' => true];

        Assert::same((new ArgumentMasker())->mask($arguments), $arguments);
    }

    public function customKeyListReplacesTheDefault(): void
    {
        $masker = new ArgumentMasker(sensitiveKeys: ['ssn']);

        $masked = $masker->mask(['ssn' => '123-45-6789', 'password' => 'kept']);

        Assert::same($masked, ['ssn' => '***', 'password' => 'kept']);
    }

    public function customKeysMatchCaseInsensitively(): void
    {
        $masker = new ArgumentMasker(sensitiveKeys: ['Ssn']);

        Assert::same($masker->mask(['SSN' => 'x']), ['SSN' => '***']);
    }

    public function maskingIsIdempotent(): void
    {
        $masker = new ArgumentMasker();
        $once = $masker->mask(['password' => 'p', 'nested' => ['token' => 't', 'ok' => 1]]);

        Assert::same($masker->mask($once), $once);
    }

    public function emptyArgumentsStayEmpty(): void
    {
        Assert::same((new ArgumentMasker())->mask([]), []);
    }

    /**
     * The guarantee the audit and telemetry bridges rely on, over generated
     * nesting instead of the three hand-written shapes above: after masking,
     * no sensitive key anywhere in the structure still carries its value.
     *
     * @param array<array-key, mixed> $arguments
     */
    #[Property(runs: 400, timeoutMs: 500)]
    public function noSensitiveValueSurvivesAtAnyDepth(array $arguments): void
    {
        $masked = (new ArgumentMasker())->mask($arguments);

        Classify::cover($this->containsSensitiveKeyAtDepth($arguments, 1, 2), 'sensitive key at depth >= 2', 15.0);
        Classify::cover($this->containsSensitiveKeyAtDepth($arguments, 1, 1), 'sensitive key anywhere', 40.0);

        $this->assertFullyMasked($masked);
    }

    /**
     * `mask()` is a projection: masking an already-masked structure must be a
     * no-op, or a second consumer in the chain (telemetry after audit) would
     * see a different payload than the first.
     *
     * @param array<array-key, mixed> $arguments
     */
    #[Property(runs: 400, timeoutMs: 500)]
    public function maskingIsIdempotentOverAnyStructure(array $arguments): void
    {
        $masker = new ArgumentMasker();
        $once = $masker->mask($arguments);

        Assert::same($masker->mask($once), $once);
    }

    /**
     * Masking must not reshape the payload: every key survives at every
     * level, in order, and only the values behind sensitive keys change.
     * Without this, "mask everything" would pass the property above.
     *
     * @param array<array-key, mixed> $arguments
     */
    #[Property(runs: 400, timeoutMs: 500)]
    public function maskingPreservesTheKeyStructureAndEveryOtherValue(array $arguments): void
    {
        $masked = (new ArgumentMasker())->mask($arguments);

        Assert::same(array_keys($masked), array_keys($arguments));

        /** @var mixed $value */
        foreach ($arguments as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE, strict: true)) {
                Assert::same($masked[$key], '***');

                continue;
            }

            if (is_array($value)) {
                Assert::same(array_keys($this->arrayAt($masked, $key)), array_keys($value));

                continue;
            }

            Assert::same($masked[$key], $value);
        }
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function noSensitiveValueSurvivesAtAnyDepthGenerators(): array
    {
        return ['arguments' => self::argumentsGenerator()];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function maskingIsIdempotentOverAnyStructureGenerators(): array
    {
        return ['arguments' => self::argumentsGenerator()];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function maskingPreservesTheKeyStructureAndEveryOtherValueGenerators(): array
    {
        return ['arguments' => self::argumentsGenerator()];
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function noSensitiveValueSurvivesAtAnyDepthExamples(): iterable
    {
        yield 'empty' => [[]];
        yield 'flat secret' => [['password' => 'p@ss']];
        yield 'sensitive key holding an array' => [['secret' => ['inner' => 'x']]];
        yield 'sensitive key nested under a list' => [['accounts' => [['token' => 'a'], ['token' => 'b']]]];
        yield 'case mismatch' => [['ToKeN' => 'x']];
        yield 'partial match stays visible' => [['tokenizer' => 'utf8']];
        yield 'integer keys around a secret' => [[0 => 'a', 'password' => 'p', 1 => 'b']];
        yield 'deep nesting' => [['a' => ['b' => ['c' => ['api_key' => 'k']]]]];
    }

    /**
     * Keys are drawn from a small pool on purpose: a random string almost
     * never collides with the sensitive list, and a generator that never
     * produces a secret tests nothing — which is what the `Classify::cover`
     * gates above enforce.
     */
    private static function argumentsGenerator(): ArbitraryInterface
    {
        $key = Gen::frequency([
            [2, Gen::elements(['password', 'token', 'ToKeN', 'api_key', 'Authorization', 'secret'])],
            [3, Gen::elements(['name', 'age', 'tokenizer', 'passwords_enabled', 'user', 'items'])],
        ]);
        $leaf = Gen::oneOf('alice', 42, true, null, 0.5, '');

        return Gen::dictOf(
            $key,
            Gen::recursive(
                $leaf,
                static fn(ArbitraryInterface $inner): ArbitraryInterface => Gen::frequency([
                    [2, Gen::dictOf($key, $inner, 0, 3)],
                    [1, Gen::arrayOf($inner, 0, 3)],
                ]),
                maxDepth: 3,
            ),
            0,
            4,
        );
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    private function containsSensitiveKeyAtDepth(array $arguments, int $depth, int $minDepth): bool
    {
        /** @var mixed $value */
        foreach ($arguments as $key => $value) {
            if ($depth >= $minDepth && is_string($key) && in_array(strtolower($key), self::SENSITIVE, strict: true)) {
                return true;
            }

            if (is_array($value) && $this->containsSensitiveKeyAtDepth($value, $depth + 1, $minDepth)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $masked
     */
    private function assertFullyMasked(array $masked): void
    {
        /** @var mixed $value */
        foreach ($masked as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE, strict: true)) {
                Assert::same($value, '***', sprintf('value behind sensitive key "%s" survived masking', $key));

                continue;
            }

            if (is_array($value)) {
                $this->assertFullyMasked($value);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $masked
     *
     * @return array<array-key, mixed>
     */
    private function arrayAt(array $masked, string|int $key): array
    {
        /** @var mixed $value */
        $value = $masked[$key] ?? null;

        Assert::true(is_array($value), sprintf('key "%s" stopped being an array', (string) $key));

        /** @var array<array-key, mixed> $value */
        return $value;
    }
}
