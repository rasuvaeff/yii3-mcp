<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * An in-memory store that remembers every write — "nothing was persisted"
 * is a fact a test can assert only if writes are observable.
 */
final class RecordingSessionStore implements SessionStoreInterface
{
    public int $writes = 0;

    private readonly InMemorySessionStore $inner;

    public function __construct()
    {
        $this->inner = new InMemorySessionStore();
    }

    #[\Override]
    public function exists(Uuid $id): bool
    {
        return $this->inner->exists($id);
    }

    #[\Override]
    public function read(Uuid $id): string|false
    {
        return $this->inner->read($id);
    }

    #[\Override]
    public function write(Uuid $id, string $data): bool
    {
        $this->writes++;

        return $this->inner->write($id, $data);
    }

    #[\Override]
    public function destroy(Uuid $id): bool
    {
        return $this->inner->destroy($id);
    }

    #[\Override]
    public function gc(): array
    {
        return $this->inner->gc();
    }
}
