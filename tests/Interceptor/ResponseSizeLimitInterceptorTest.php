<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use InvalidArgumentException;
use Mcp\Exception\ToolCallException;
use Rasuvaeff\Yii3Mcp\Interceptor\ResponseSizeLimitInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ResponseSizeLimitInterceptor::class)]
final class ResponseSizeLimitInterceptorTest
{
    public function stringResultAtTheLimitIsUntouched(): void
    {
        $interceptor = new ResponseSizeLimitInterceptor(maxBytes: 5);

        Assert::same($interceptor->intercept($this->context(), static fn(): string => 'abcde'), 'abcde');
    }

    public function stringResultOverTheLimitIsTruncatedWithAMarker(): void
    {
        $interceptor = new ResponseSizeLimitInterceptor(maxBytes: 5);

        $result = $interceptor->intercept($this->context(), static fn(): string => 'abcdefghij');

        // truncated content comes first, the marker after — not the other way round
        Assert::true(str_starts_with((string) $result, 'abcde'));
        Assert::string($result)->contains('truncated, showing 5 of 10 bytes');
        Assert::false(str_contains((string) $result, 'fghij'));
    }

    public function multiByteResultIsCutOnACharacterBoundary(): void
    {
        // 'привет' is 12 bytes; a byte-wise cut at 5 would split the third
        // character and make the SDK's json_encode of the whole response fail
        $interceptor = new ResponseSizeLimitInterceptor(maxBytes: 5);

        $result = (string) $interceptor->intercept($this->context(), static fn(): string => 'привет');

        Assert::true(str_starts_with($result, 'пр'));
        Assert::same(preg_match('//u', $result), 1);
        json_encode($result, JSON_THROW_ON_ERROR);
        // the marker reports what was actually kept (4 bytes), not the limit
        Assert::string($result)->contains('truncated, showing 4 of 12 bytes');
    }

    public function nonUtf8StringWithinTheLimitThrows(): void
    {
        // Utf8::cut is not even reached here (the string is within the
        // limit) — the check must not be conditional on truncation actually
        // happening, or a short foreign body would still crash the SDK's
        // envelope encoding later, just less often
        $interceptor = new ResponseSizeLimitInterceptor(maxBytes: 10);

        $caught = null;

        try {
            $interceptor->intercept($this->context(toolName: 'legacyTool'), static fn(): string => "\x80\x80");
        } catch (ToolCallException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('legacyTool');
        Assert::string($caught->getMessage())->contains('not valid UTF-8');
    }

    public function nonUtf8StringOverTheLimitThrowsInsteadOfBeingTruncated(): void
    {
        // 0xFF is never a valid UTF-8 byte, but Utf8::cut's lead-byte
        // classification (a simple >= range match, not a real validator)
        // misreads it as a 4-byte lead and keeps it — along with just
        // enough trailing bytes to satisfy that (wrong) byte count — because
        // the naive check "enough bytes remain" is satisfied. The result is
        // still invalid UTF-8 and must not be returned as if it were fine.
        $interceptor = new ResponseSizeLimitInterceptor(maxBytes: 6);

        Expect::exception(ToolCallException::class);

        $interceptor->intercept($this->context(), static fn(): string => "ab\xFFxyzEXTRA");
    }

    public function arrayResultAtTheLimitIsReturnedUnchanged(): void
    {
        $payload = ['a' => 1];
        $interceptor = new ResponseSizeLimitInterceptor(maxBytes: strlen(json_encode($payload, JSON_THROW_ON_ERROR)));

        Assert::same($interceptor->intercept($this->context(), static fn(): array => $payload), $payload);
    }

    public function arrayResultOverTheLimitThrowsInsteadOfTruncating(): void
    {
        $interceptor = new ResponseSizeLimitInterceptor(maxBytes: 5);

        $caught = null;

        try {
            $interceptor->intercept($this->context(toolName: 'bigTool'), static fn(): array => ['slug' => 'a-very-long-value']);
        } catch (ToolCallException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('bigTool');
        Assert::string($caught->getMessage())->contains('exceeds the configured limit of 5 bytes');
    }

    public function nullResultWithinTheLimitPassesThrough(): void
    {
        $interceptor = new ResponseSizeLimitInterceptor(maxBytes: 10);

        Assert::null($interceptor->intercept($this->context(), static fn(): mixed => null));
    }

    #[DataProvider('invalidLimitProvider')]
    public function throwsOnNonPositiveLimit(int $maxBytes): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ResponseSizeLimitInterceptor($maxBytes);
    }

    public function limitOfExactlyOneIsAccepted(): void
    {
        $interceptor = new ResponseSizeLimitInterceptor(maxBytes: 1);

        Assert::same($interceptor->intercept($this->context(), static fn(): string => 'x'), 'x');
    }

    public static function invalidLimitProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    private function context(string $toolName = 'x'): ToolCallContext
    {
        return new ToolCallContext(toolName: $toolName, arguments: []);
    }
}
