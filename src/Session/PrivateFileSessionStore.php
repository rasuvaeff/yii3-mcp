<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Session;

use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Owner-only file session store: the SDK's {@see FileSessionStore} creates
 * its directory `0775` and writes files with the process umask (typically
 * `0644`), which lets any local OS user read session JSON — client metadata,
 * UUID-associated state, queued messages, and everything needed to replay a
 * session id. This wrapper creates the directory with effective mode `0700`
 * (chmod after mkdir, so the umask cannot widen it) and clamps every written
 * session file to `0600`.
 *
 * A pre-existing directory is NOT re-chmodded — deliberately sharing one is
 * the operator's call; `mcp:doctor` reports a directory readable by group or
 * others instead.
 *
 * @api
 */
final readonly class PrivateFileSessionStore implements SessionStoreInterface
{
    private FileSessionStore $inner;

    public function __construct(
        private string $directory,
        int $ttl = 3600,
    ) {
        if (!is_dir($directory) && @mkdir($directory, 0o700, true)) {
            // an explicit chmod beats the umask, which mkdir cannot
            @chmod($directory, 0o700);
        }

        $this->inner = new FileSessionStore($directory, $ttl);
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
        $written = $this->inner->write($id, $data);

        if ($written) {
            @chmod($this->directory . \DIRECTORY_SEPARATOR . $id->toRfc4122(), 0o600);
        }

        return $written;
    }

    #[\Override]
    public function destroy(Uuid $id): bool
    {
        return $this->inner->destroy($id);
    }

    /**
     * @return list<Uuid>
     */
    #[\Override]
    public function gc(): array
    {
        return array_values($this->inner->gc());
    }
}
