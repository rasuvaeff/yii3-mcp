<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Server\Transport\CallbackStream;
use Nyholm\Psr7\Stream;
use Rasuvaeff\Yii3Mcp\OutputBufferReleasingStream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(OutputBufferReleasingStream::class)]
final class OutputBufferReleasingStreamTest
{
    /**
     * The SDK's SSE callback echoes each frame and calls flush(): it must run
     * with no userland buffer above the base level, or frames pile up until
     * the stream closes. Output already sitting in those buffers is passed on
     * in order, not dropped.
     */
    public function readEndsBuffersAboveTheBaseLevelBeforeTheCallbackWrites(): void
    {
        $this->assertReleasedBy(static fn(OutputBufferReleasingStream $stream): string => $stream->read(8192));
    }

    public function getContentsEndsBuffersAboveTheBaseLevelBeforeTheCallbackWrites(): void
    {
        $this->assertReleasedBy(static fn(OutputBufferReleasingStream $stream): string => $stream->getContents());
    }

    public function castingToStringEndsBuffersAboveTheBaseLevelBeforeTheCallbackWrites(): void
    {
        $this->assertReleasedBy(static fn(OutputBufferReleasingStream $stream): string => (string) $stream);
    }

    public function everythingElseIsDelegated(): void
    {
        $stream = new OutputBufferReleasingStream(Stream::create('payload'));

        Assert::same($stream->getSize(), 7);
        Assert::true($stream->isSeekable());
        Assert::true($stream->isReadable());
        Assert::true($stream->isWritable());
        $stream->seek(3);
        Assert::same($stream->tell(), 3);
        Assert::false($stream->eof());
        $stream->rewind();
        Assert::same($stream->tell(), 0);
        Assert::same($stream->getMetadata('mode'), 'w+b');
    }

    /**
     * @param \Closure(OutputBufferReleasingStream): string $consume
     */
    private function assertReleasedBy(\Closure $consume): void
    {
        // the outer buffer stands in for the SAPI: it collects what got through
        ob_start();
        $base = ob_get_level();
        $levelAtWrite = null;

        try {
            ob_start();
            echo 'pending|';
            ob_start();

            $stream = new OutputBufferReleasingStream(new CallbackStream(static function () use (&$levelAtWrite): void {
                $levelAtWrite = ob_get_level();
                echo 'frame';
            }), $base);

            Assert::same($consume($stream), '');
            Assert::same($levelAtWrite, $base);
            Assert::same(ob_get_level(), $base);
        } finally {
            while (ob_get_level() > $base) {
                ob_end_clean();
            }

            $sent = (string) ob_get_clean();
        }

        Assert::same($sent, 'pending|frame');
    }
}
