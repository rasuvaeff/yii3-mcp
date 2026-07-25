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
