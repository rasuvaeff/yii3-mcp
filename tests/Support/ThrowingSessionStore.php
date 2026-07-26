<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Server\Session\SessionStoreInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class ThrowingSessionStore implements SessionStoreInterface
{
    public function __construct(
        private readonly string $message = 'disk on fire',
    ) {}

    #[\Override]
    public function exists(Uuid $id): bool
    {
        throw new RuntimeException($this->message);
    }

    #[\Override]
    public function read(Uuid $id): string|false
    {
        throw new RuntimeException($this->message);
    }

    #[\Override]
    public function write(Uuid $id, string $data): bool
    {
        throw new RuntimeException($this->message);
    }

    #[\Override]
    public function destroy(Uuid $id): bool
    {
        throw new RuntimeException($this->message);
    }

    #[\Override]
    public function gc(): array
    {
        return [];
    }
}
