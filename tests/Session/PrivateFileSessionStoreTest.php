<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Session;

use Rasuvaeff\Yii3Mcp\Session\PrivateFileSessionStore;
use Rasuvaeff\Yii3Mcp\Session\SessionDirectory;
use Symfony\Component\Uid\Uuid;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Test]
#[Covers(PrivateFileSessionStore::class)]
#[Covers(SessionDirectory::class)]
final class PrivateFileSessionStoreTest
{
    private string $directory = '';

    #[AfterTest]
    public function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->directory);
        }
    }

    public function createsTheDirectoryOwnerOnlyRegardlessOfUmask(): void
    {
        $previousUmask = umask(0);

        try {
            new PrivateFileSessionStore($this->freshDirectory());
        } finally {
            umask($previousUmask);
        }

        Assert::same(substr(sprintf('%o', (int) fileperms($this->directory)), -3), '700');
    }

    public function sessionFilesAreClampedToOwnerOnly(): void
    {
        $store = new PrivateFileSessionStore($this->freshDirectory());
        $id = Uuid::v4();

        $previousUmask = umask(0);

        try {
            Assert::true($store->write($id, '{"secret":"state"}'));
        } finally {
            umask($previousUmask);
        }

        $path = $this->directory . '/' . $id->toRfc4122();
        Assert::same(substr(sprintf('%o', (int) fileperms($path)), -3), '600');
        Assert::same($store->read($id), '{"secret":"state"}');
    }

    public function preExistingDirectoryPermissionsAreLeftAlone(): void
    {
        // deliberately sharing a wider directory is the operator's call —
        // the store must not re-chmod it behind their back (mcp:doctor
        // reports it instead)
        $directory = $this->freshDirectory();
        mkdir($directory, 0o755, true);
        chmod($directory, 0o755);

        new PrivateFileSessionStore($directory);

        clearstatcache();
        Assert::same(substr(sprintf('%o', (int) fileperms($directory)), -3), '755');
    }

    public function roundTripAndDestroyDelegateToTheFileStore(): void
    {
        $store = new PrivateFileSessionStore($this->freshDirectory());
        $id = Uuid::v4();

        Assert::false($store->exists($id));
        $store->write($id, 'data');
        Assert::true($store->exists($id));
        $store->destroy($id);
        Assert::false($store->exists($id));
    }

    public function defaultDirectoryIsApplicationSpecific(): void
    {
        $a = SessionDirectory::resolve('', 'app-a');
        $b = SessionDirectory::resolve('', 'app-b');

        Assert::true($a !== $b);
        // the exact shape is a contract: temp dir + readable slug + a
        // 16-hex-char hash of the FULL server name
        Assert::same($a, sys_get_temp_dir() . '/yii3-mcp-sessions-app-a-' . substr(hash('sha256', 'app-a'), 0, 16));
        Assert::same(SessionDirectory::resolve('/explicit/dir', 'app-a'), '/explicit/dir');
    }

    public function slugIsTrimmedOfSeparatorArtifacts(): void
    {
        // "!app!" slugs to "-app-"; the trim keeps the path readable
        Assert::same(
            SessionDirectory::resolve('', '!app!'),
            sys_get_temp_dir() . '/yii3-mcp-sessions-app-' . substr(hash('sha256', '!app!'), 0, 16),
        );
    }

    public function fullyUnsluggableServerNameFallsBackToHashOnly(): void
    {
        Assert::same(
            SessionDirectory::resolve('', '!!!'),
            sys_get_temp_dir() . '/yii3-mcp-sessions-' . substr(hash('sha256', '!!!'), 0, 16),
        );
    }

    private function freshDirectory(): string
    {
        return $this->directory = sys_get_temp_dir() . '/yii3-mcp-private-store-' . bin2hex(random_bytes(8));
    }
}
