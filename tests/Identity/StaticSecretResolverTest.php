<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Identity;

use InvalidArgumentException;
use Rasuvaeff\Yii3Mcp\Identity\StaticSecretResolver;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(StaticSecretResolver::class)]
final class StaticSecretResolverTest
{
    public function resolvesTheClientOwningThePresentedSecret(): void
    {
        $resolver = new StaticSecretResolver([
            'ci' => 'ci-secret',
            'claude' => 'claude-secret',
        ]);

        Assert::same($resolver->resolve('claude-secret'), 'claude');
        Assert::same($resolver->resolve('ci-secret'), 'ci');
    }

    public function bothActiveSecretsResolveDuringARotationWindow(): void
    {
        $resolver = new StaticSecretResolver([
            'claude' => ['old-secret', 'new-secret'],
        ]);

        Assert::same($resolver->resolve('old-secret'), 'claude');
        Assert::same($resolver->resolve('new-secret'), 'claude');
    }

    public function unknownSecretResolvesToNull(): void
    {
        $resolver = new StaticSecretResolver(['claude' => 'claude-secret']);

        Assert::null($resolver->resolve('revoked-secret'));
        Assert::null($resolver->resolve(''));
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function rejectsAnEmptyClientMap(): void
    {
        new StaticSecretResolver([]);
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function rejectsAnEmptyClientId(): void
    {
        new StaticSecretResolver(['' => 'secret']);
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function rejectsAClientWithoutSecrets(): void
    {
        new StaticSecretResolver(['claude' => []]);
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function rejectsAnEmptySecret(): void
    {
        new StaticSecretResolver(['claude' => ['ok-secret', '']]);
    }
}
