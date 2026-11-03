<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * A body that serves its chunks, then answers every further read with ''
 * while never reporting EOF (a stalled upstream). A reader that polls it
 * again after the empty answer fails the test instead of spinning.
 */
final class StalledStream implements StreamInterface
{
    public int $polls = 0;

    /**
     * @param list<string> $chunks
     */
    public function __construct(
        private array $chunks,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        return '';
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
        return 0;
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
        if (++$this->polls > 2) {
            throw new \LogicException('A stalled body was polled again after an empty read');
        }

        return array_shift($this->chunks) ?? '';
    }

    #[\Override]
    public function getContents(): string
    {
        throw new \LogicException('Not materialized');
    }

    #[\Override]
    public function getMetadata(?string $key = null)
    {
        return null;
    }
}
