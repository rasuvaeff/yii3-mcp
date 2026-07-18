<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Mcp\Exception\ToolCallException;
use Rasuvaeff\Yii3Mcp\Interceptor\RateLimitInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallLimiterInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RateLimitInterceptor::class)]
final class RateLimitInterceptorTest
{
    public function allowedCallProceedsWithTheResolvedIdentity(): void
    {
        $limiter = new RecordingLimiter(allow: true);
        $interceptor = new RateLimitInterceptor($limiter);

        $result = $interceptor->intercept($this->context(clientId: 'claude'), static fn(): string => 'ran');

        Assert::same($result, 'ran');
        Assert::same($limiter->seen, [['claude', 'greet']]);
    }

    public function rejectedCallNamesTheClientAndTool(): void
    {
        $interceptor = new RateLimitInterceptor(new RecordingLimiter(allow: false));
        $caught = null;

        try {
            $interceptor->intercept($this->context(clientId: 'claude'), static fn(): string => 'ran');
        } catch (ToolCallException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('claude');
        Assert::string($caught->getMessage())->contains('greet');
    }

    public function limiterOutageFailsClosed(): void
    {
        $limiter = new RecordingLimiter(allow: true, failure: new \RuntimeException('redis down'));
        $interceptor = new RateLimitInterceptor($limiter);
        $called = false;
        $caught = null;

        try {
            $interceptor->intercept($this->context(clientId: 'claude'), static function () use (&$called): string {
                $called = true;

                return 'ran';
            });
        } catch (ToolCallException $caught) {
        }

        Assert::notNull($caught);
        Assert::false($called);
        Assert::string($caught->getMessage())->contains('fails closed');
        Assert::instanceOf($caught->getPrevious(), \RuntimeException::class);
    }

    public function transportWithoutIdentityUsesTheFallbackClientId(): void
    {
        $limiter = new RecordingLimiter(allow: true);
        $interceptor = new RateLimitInterceptor($limiter, fallbackClientId: 'stdio');

        $interceptor->intercept($this->context(clientId: null), static fn(): string => 'ran');

        Assert::same($limiter->seen, [['stdio', 'greet']]);
    }

    private function context(?string $clientId): ToolCallContext
    {
        return new ToolCallContext(toolName: 'greet', arguments: [], clientId: $clientId);
    }
}

final class RecordingLimiter implements ToolCallLimiterInterface
{
    /** @var list<array{0: string, 1: string}> */
    public array $seen = [];

    public function __construct(
        private readonly bool $allow,
        private readonly ?\Throwable $failure = null,
    ) {}

    #[\Override]
    public function allow(string $clientId, string $toolName): bool
    {
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        $this->seen[] = [$clientId, $toolName];

        return $this->allow;
    }
}
