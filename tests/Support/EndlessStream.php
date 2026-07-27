<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * Never-ending, size-less body (a chunked upstream response): reads serve
 * 8 KiB blocks forever and count the bytes handed out, so a test can prove
 * a bounded reader stopped instead of buffering until memory ran out.
 */
final class EndlessStream implements StreamInterface
{
    public int $servedBytes = 0;

    #[\Override]
    public function __toString(): string
    {
        throw new \LogicException('EndlessStream must never be materialized');
    }

    #[\Override]
    public function close(): void {}

    #[\Override]
    public function detach()
    {
        return null;
    }

    #[\Override]
    public function getSize(): ?int
    {
        return null;
    }

    #[\Override]
    public function tell(): int
    {
        return $this->servedBytes;
    }

    #[\Override]
    public function eof(): bool
    {
        return false;
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return false;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('Not seekable');
    }

    #[\Override]
    public function rewind(): void
    {
        throw new \RuntimeException('Not seekable');
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        throw new \RuntimeException('Not writable');
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        $this->servedBytes += $length;

        return str_repeat('x', $length);
    }

    #[\Override]
    public function getContents(): string
    {
        throw new \LogicException('EndlessStream must never be materialized');
    }

    #[\Override]
    public function getMetadata(?string $key = null)
    {
        return null;
    }
}
