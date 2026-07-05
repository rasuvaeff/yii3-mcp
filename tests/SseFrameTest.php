<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Rasuvaeff\Yii3Mcp\Testing\SseFrame;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SseFrame::class)]
final class SseFrameTest
{
    public function plainJsonBodyPassesThroughUntouched(): void
    {
        Assert::same(SseFrame::payload('{"jsonrpc":"2.0","result":{}}'), '{"jsonrpc":"2.0","result":{}}');
    }

    public function emptyBodyStaysEmpty(): void
    {
        Assert::same(SseFrame::payload(''), '');
    }

    public function extractsSingleDataLine(): void
    {
        Assert::same(SseFrame::payload("event: message\ndata: {\"a\":1}\n\n"), '{"a":1}');
    }

    public function joinsMultiLineDataFieldsWithNewlines(): void
    {
        Assert::same(SseFrame::payload("data: {\"a\":\ndata:  1}\n\n"), "{\"a\":\n 1}");
    }

    public function usesOnlyTheFirstEvent(): void
    {
        Assert::same(SseFrame::payload("data: first\n\ndata: second\n\n"), 'first');
    }

    public function normalizesCrlfFraming(): void
    {
        // multi-line data + a second event: without CRLF normalization the
        // event boundary is missed and the captures keep trailing \r
        Assert::same(
            SseFrame::payload("data: {\"a\":\r\ndata:  1}\r\n\r\ndata: second\r\n\r\n"),
            "{\"a\":\n 1}",
        );
    }

    public function dataFieldWithoutSpaceAfterColonIsAccepted(): void
    {
        Assert::same(SseFrame::payload("data:{\"a\":1}\n\n"), '{"a":1}');
    }

    public function eventWithoutDataFieldYieldsEmptyPayload(): void
    {
        Assert::same(SseFrame::payload("event: ping\n\n"), '');
    }

    public function leadingWhitespaceBeforeSseFramingIsTolerated(): void
    {
        Assert::same(SseFrame::payload("\n\ndata: {\"a\":1}\n\n"), '{"a":1}');
    }
}
