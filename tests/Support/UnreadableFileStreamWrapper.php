<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

/**
 * Reports every path as an existing regular file that cannot be opened.
 * File permission bits cannot stand in for this: root ignores them, and
 * Windows has none.
 */
final class UnreadableFileStreamWrapper
{
    /**
     * @return array<string, int>
     */
    public function url_stat(): array
    {
        return [
            'dev' => 0,
            'ino' => 0,
            'mode' => 0o100644,
            'nlink' => 1,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => 10,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => -1,
            'blocks' => -1,
        ];
    }

    public function stream_open(): bool
    {
        return false;
    }
}
