<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

/**
 * Reports every path as an existing directory nobody may write to
 * (`dr-xr-xr-x`). Permission bits on a real directory cannot stand in for
 * this: root ignores them, and Windows has none.
 */
final class ReadOnlyDirectoryStreamWrapper
{
    /**
     * @return array<string, int>
     */
    public function url_stat(): array
    {
        return [
            'dev' => 0,
            'ino' => 0,
            'mode' => 0o040555,
            'nlink' => 1,
            'uid' => function_exists('posix_getuid') ? posix_getuid() : 0,
            'gid' => function_exists('posix_getgid') ? posix_getgid() : 0,
            'rdev' => 0,
            'size' => 0,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => -1,
            'blocks' => -1,
        ];
    }
}
