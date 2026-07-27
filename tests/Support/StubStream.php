<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * Configurable PSR-7 body for bounded-read tests: an advertised size that may
 * lie (or be absent, as with chunked transfer), fixed content served in
 * chunks, and an optional "must never be read" mode that throws on the first
 * read — proving an advertised-size rejection really happened before a
 * single byte was pulled.
 */
final class StubStream implements StreamInterface
{
    private int $position = 0;

    /**
     * @param ?int $advertisedSize what getSize() reports (null = unknown/chunked)
     */
    public function __construct(
        private readonly string $content = '',
        private readonly ?int $advertisedSize = null,
        private readonly bool $throwOnRead = false,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        return $this->content;
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
        return $this->advertisedSize;
    }

    #[\Override]
    public function tell(): int
    {
        return $this->position;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->position >= strlen($this->content);
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
        if ($this->throwOnRead) {
            throw new \LogicException('This body must never be read');
        }

        $chunk = substr($this->content, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    #[\Override]
    public function getContents(): string
    {
        if ($this->throwOnRead) {
            throw new \LogicException('This body must never be read');
        }

        $rest = substr($this->content, $this->position);
        $this->position = strlen($this->content);

        return $rest;
    }

    #[\Override]
    public function getMetadata(?string $key = null)
    {
        return null;
    }
}
