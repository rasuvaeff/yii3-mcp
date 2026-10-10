<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Psr\Http\Message\StreamInterface;

/**
 * Body of a `text/event-stream` response: ends PHP's userland output buffers
 * right before the SDK's callback stream writes its frames, so each frame's
 * `flush()` reaches the SAPI instead of piling up in a buffer.
 *
 * The SDK's stateless responder (mcp/sdk 0.8.1, `StatelessResponder::sse()`)
 * writes every frame with `echo` + `flush()` only. `flush()` does not touch
 * the buffer `output_buffering` (4096 in both shipped php.ini files) opens
 * before the application starts, and `yiisoft/psr-emitter` ends only the
 * buffers opened above the level it recorded — so a `subscriptions/listen`
 * stream reached the client in one piece when it closed. The handshake-era
 * transport calls `ob_flush()` itself and is unaffected; ending the buffers
 * first is harmless for it.
 *
 * Buffers are ended down to `$baseLevel` (0 — all of them — in production);
 * a non-removable buffer stops the release.
 *
 * @internal
 */
final readonly class OutputBufferReleasingStream implements StreamInterface
{
    public function __construct(
        private StreamInterface $inner,
        private int $baseLevel = 0,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        $this->release();

        return (string) $this->inner;
    }

    #[\Override]
    public function close(): void
    {
        $this->inner->close();
    }

    #[\Override]
    public function detach()
    {
        return $this->inner->detach();
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    #[\Override]
    public function tell(): int
    {
        return $this->inner->tell();
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->inner->eof();
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    #[\Override]
    public function rewind(): void
    {
        $this->inner->rewind();
    }

    #[\Override]
    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    #[\Override]
    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    #[\Override]
    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    #[\Override]
    public function read(int $length): string
    {
        $this->release();

        return $this->inner->read($length);
    }

    #[\Override]
    public function getContents(): string
    {
        $this->release();

        return $this->inner->getContents();
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return $this->inner->getMetadata($key);
    }

    private function release(): void
    {
        while (ob_get_level() > $this->baseLevel) {
            // a non-removable buffer refuses with a notice and stays: stop there
            if (!@ob_end_flush()) {
                return;
            }
        }
    }
}
