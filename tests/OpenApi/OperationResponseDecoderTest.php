<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\OperationFailedException;
use Rasuvaeff\Yii3Mcp\OpenApi\OperationResponseDecoder;
use Rasuvaeff\Yii3Mcp\Tests\Support\EndlessStream;
use Rasuvaeff\Yii3Mcp\Tests\Support\StalledStream;
use Rasuvaeff\Yii3Mcp\Tests\Support\StubStream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(OperationResponseDecoder::class)]
final class OperationResponseDecoderTest
{
    #[DataProvider('invalidLimitProvider')]
    public function aLimitBelowOneByteIsRejected(int $limit): void
    {
        $caught = null;

        try {
            new OperationResponseDecoder(maxResponseBytes: $limit);
        } catch (InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), sprintf('Max response bytes must be at least 1, %d given', $limit));
    }

    public static function invalidLimitProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
    }

    public function aLimitOfExactlyOneByteIsAccepted(): void
    {
        $decoder = new OperationResponseDecoder(maxResponseBytes: 1);

        Assert::same($decoder->decode($this->response('7'), 'tool'), 7);
    }

    public function aSizelessBodyLargerThanOneReadChunkIsAccumulatedInOrder(): void
    {
        $payload = ['items' => range(1, 6_000)];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        Assert::true(strlen($json) > 16_384);

        $decoded = (new OperationResponseDecoder())->decode($this->response($json), 'tool');

        Assert::same($decoded, $payload);
    }

    public function aSizelessBodyOverTheCapIsRefusedWithoutBeingReadToTheEnd(): void
    {
        $stream = new StubStream(content: str_repeat('a', 20_000));
        $decoder = new OperationResponseDecoder(maxResponseBytes: 100);

        $caught = null;

        try {
            $decoder->decode(new Response(200, [], $stream), 'lookup');
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), 'Tool "lookup" response exceeds the 100-byte limit; refusing to buffer it');
        // the read was abandoned at the cap, not carried on to the end
        Assert::true($stream->tell() < 20_000);
    }

    public function theReadStopsWithinOneEightKilobyteChunkOfTheCap(): void
    {
        $stream = new EndlessStream();
        $decoder = new OperationResponseDecoder(maxResponseBytes: 16_384);

        $caught = null;

        try {
            $decoder->decode(new Response(200, [], $stream), 'lookup');
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        // 8192-byte reads: two of them reach the cap exactly (not over it),
        // the third crosses it
        Assert::same($stream->servedBytes, 24_576);
    }

    public function aStalledBodyEndsTheReadInsteadOfPollingForever(): void
    {
        $stream = new StalledStream(chunks: ['{"partial":']);

        $decoded = (new OperationResponseDecoder())->decode(new Response(200, [], $stream), 'tool');

        // whatever arrived before the stall is what the tool result is
        Assert::same($decoded, '{"partial":');
        Assert::same($stream->polls, 2);
    }

    #[DataProvider('nonUtf8ErrorProvider')]
    public function aNonUtf8ErrorBodyIsReplacedByAPlaceholder(string $body, string $expected): void
    {
        $decoder = new OperationResponseDecoder();

        $caught = null;

        try {
            $decoder->decode(new Response(500, [], new StubStream(content: $body)), 'lookup');
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), $expected);
    }

    public static function nonUtf8ErrorProvider(): iterable
    {
        yield 'short body' => ["\xff\xfeabc", 'Tool "lookup" failed with HTTP 500: <non-UTF-8 response body, 5 bytes>'];
        yield 'body cut at the excerpt window' => [
            str_repeat("\xff", 5_000),
            'Tool "lookup" failed with HTTP 500: <non-UTF-8 response body, over 2001 bytes>',
        ];
    }

    private function response(string $body): Response
    {
        return new Response(200, [], new StubStream(content: $body));
    }
}
