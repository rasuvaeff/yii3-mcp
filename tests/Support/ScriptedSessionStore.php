<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * An in-memory store whose read() can be scripted and whose writes and
 * existence checks are observable: "the SDK was never asked" and "nothing
 * was persisted" are facts a test can assert only if both are recorded.
 */
final class ScriptedSessionStore implements SessionStoreInterface
{
    /**
     * When not null, returned by every read() instead of the stored data.
     */
    public string|false|null $readOverride = null;

    /**
     * @var list<string>
     */
    public array $writes = [];

    public int $existsCalls = 0;

    private readonly InMemorySessionStore $inner;

    public function __construct()
    {
        $this->inner = new InMemorySessionStore();
    }

    #[\Override]
    public function exists(Uuid $id): bool
    {
        ++$this->existsCalls;

        return $this->inner->exists($id);
    }

    #[\Override]
    public function read(Uuid $id): string|false
    {
        return $this->readOverride ?? $this->inner->read($id);
    }

    #[\Override]
    public function write(Uuid $id, string $data): bool
    {
        $this->writes[] = $data;

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
