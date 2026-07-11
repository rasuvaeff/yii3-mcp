<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Rasuvaeff\Yii3Mcp\Interceptor\ArgumentMasker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ArgumentMasker::class)]
final class ArgumentMaskerTest
{
    #[DataProvider('defaultKeysProvider')]
    public function masksEveryDefaultKey(string $key): void
    {
        Assert::same((new ArgumentMasker())->mask([$key => 'value']), [$key => '***']);
    }

    public static function defaultKeysProvider(): iterable
    {
        yield 'password' => ['password'];
        yield 'secret' => ['secret'];
        yield 'token' => ['token'];
        yield 'api_key' => ['api_key'];
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
}
