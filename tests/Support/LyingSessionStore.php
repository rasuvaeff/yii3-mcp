<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Accepts writes but never reads anything back — models a store whose disk
 * silently drops data.
 */
final class LyingSessionStore implements SessionStoreInterface
{
    #[\Override]
    public function exists(Uuid $id): bool
    {
        return false;
    }

    #[\Override]
    public function read(Uuid $id): string|false
    {
        return false;
    }

    #[\Override]
    public function write(Uuid $id, string $data): bool
    {
        return true;
    }

    #[\Override]
    public function destroy(Uuid $id): bool
    {
        return true;
    }

    #[\Override]
    public function gc(): array
    {
        return [];
    }
}
