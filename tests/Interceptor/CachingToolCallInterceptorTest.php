<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Rasuvaeff\Yii3Mcp\Interceptor\CachingToolCallInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeCache;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CachingToolCallInterceptor::class)]
final class CachingToolCallInterceptorTest
{
    public function uncachedToolAlwaysCallsNext(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: []);
        $calls = 0;

        $interceptor->intercept($this->context('otherTool'), static function () use (&$calls): string {
            ++$calls;

            return 'result';
        });
        $interceptor->intercept($this->context('otherTool'), static function () use (&$calls): string {
            ++$calls;

            return 'result';
        });

        Assert::same($calls, 2);
    }

    public function secondCallWithTheSameArgumentsIsServedFromCache(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60]);
        $calls = 0;
        $handler = static function () use (&$calls): string {
            ++$calls;

            return 'computed-' . $calls;
        };

        $first = $interceptor->intercept($this->context('cachedTool'), $handler);
        $second = $interceptor->intercept($this->context('cachedTool'), $handler);

        Assert::same($first, 'computed-1');
        Assert::same($second, 'computed-1');
        Assert::same($calls, 1);
    }

    public function differentToolsGetDifferentCacheEntries(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['toolA' => 60, 'toolB' => 60]);

        $first = $interceptor->intercept($this->context('toolA'), static fn(): string => 'from-a');
        $second = $interceptor->intercept($this->context('toolB'), static fn(): string => 'from-b');

        Assert::same($first, 'from-a');
        Assert::same($second, 'from-b');
    }

    public function clientIdAndToolNameCannotBeConfusedByConcatenation(): void
    {
        // without a separator, ('a','bc') and ('ab','c') concatenate to the
        // same "abc" — the key must keep them apart
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['bc' => 60, 'c' => 60]);

        $first = $interceptor->intercept($this->context('bc', clientId: 'a'), static fn(): string => 'first');
        $second = $interceptor->intercept($this->context('c', clientId: 'ab'), static fn(): string => 'second');

        Assert::same($first, 'first');
        Assert::same($second, 'second');
    }

    public function allArgumentKeysAreConsideredNotJustTheFirst(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60]);
        $calls = 0;
        $handler = static function () use (&$calls): int {
            ++$calls;

            return $calls;
        };

        $interceptor->intercept($this->context('cachedTool', ['a' => 1, 'b' => 1]), $handler);
        $interceptor->intercept($this->context('cachedTool', ['a' => 1, 'b' => 2]), $handler);

        Assert::same($calls, 2);
    }

    public function nestedArgumentKeyOrderIsCanonicalizedRecursively(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60]);
        $calls = 0;
        $handler = static function () use (&$calls): int {
            ++$calls;

            return $calls;
        };

        $interceptor->intercept($this->context('cachedTool', ['filter' => ['a' => 1, 'b' => 2]]), $handler);
        $interceptor->intercept($this->context('cachedTool', ['filter' => ['b' => 2, 'a' => 1]]), $handler);

        Assert::same($calls, 1);
    }

    public function differentArgumentsGetDifferentCacheEntries(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60]);
        $calls = 0;
        $handler = static function () use (&$calls): int {
            ++$calls;

            return $calls;
        };

        $interceptor->intercept($this->context('cachedTool', ['id' => 1]), $handler);
        $interceptor->intercept($this->context('cachedTool', ['id' => 2]), $handler);

        Assert::same($calls, 2);
    }

    public function argumentKeyOrderDoesNotAffectTheCacheKey(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60]);
        $calls = 0;
        $handler = static function () use (&$calls): int {
            ++$calls;

            return $calls;
        };

        $interceptor->intercept($this->context('cachedTool', ['a' => 1, 'b' => 2]), $handler);
        $interceptor->intercept($this->context('cachedTool', ['b' => 2, 'a' => 1]), $handler);

        Assert::same($calls, 1);
    }

    public function differentClientsNeverShareACacheEntry(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60]);
        $calls = 0;
        $handler = static function () use (&$calls): int {
            ++$calls;

            return $calls;
        };

        $interceptor->intercept($this->context('cachedTool', clientId: 'client-a'), $handler);
        $interceptor->intercept($this->context('cachedTool', clientId: 'client-b'), $handler);

        Assert::same($calls, 2);
    }

    public function nullClientIdFallsBackToAnonymousAndStillCaches(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60]);
        $calls = 0;
        $handler = static function () use (&$calls): int {
            ++$calls;

            return $calls;
        };

        $interceptor->intercept($this->context('cachedTool', clientId: null), $handler);
        $interceptor->intercept($this->context('cachedTool', clientId: null), $handler);

        Assert::same($calls, 1);
    }

    public function ttlIsPassedToTheCache(): void
    {
        $cache = new FakeCache();
        $interceptor = new CachingToolCallInterceptor($cache, ttlSeconds: ['cachedTool' => 42]);

        $interceptor->intercept($this->context('cachedTool'), static fn(): string => 'x');

        Assert::same($cache->lastTtl, 42);
    }

    public function thrownExceptionsAreNeverCached(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60]);
        $calls = 0;
        $handler = static function () use (&$calls): string {
            ++$calls;

            if ($calls === 1) {
                throw new RuntimeException('downstream failed');
            }

            return 'ok';
        };

        $caught = null;

        try {
            $interceptor->intercept($this->context('cachedTool'), $handler);
        } catch (RuntimeException $caught) {
        }

        Assert::notNull($caught);

        $result = $interceptor->intercept($this->context('cachedTool'), $handler);

        Assert::same($result, 'ok');
        Assert::same($calls, 2);
    }

    public function cacheReadFailureFailsOpen(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(throwOnRead: true), ttlSeconds: ['cachedTool' => 60]);

        Assert::same($interceptor->intercept($this->context('cachedTool'), static fn(): string => 'ok'), 'ok');
    }

    public function cacheReadFailureSkipsTheWriteBackToo(): void
    {
        $cache = new FakeCache(throwOnRead: true);
        $interceptor = new CachingToolCallInterceptor($cache, ttlSeconds: ['cachedTool' => 60]);

        $interceptor->intercept($this->context('cachedTool'), static fn(): string => 'ok');

        Assert::same($cache->values, []);
    }

    public function cacheWriteFailureFailsOpen(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(throwOnWrite: true), ttlSeconds: ['cachedTool' => 60]);

        Assert::same($interceptor->intercept($this->context('cachedTool'), static fn(): string => 'ok'), 'ok');
    }

    public function nullResultIsCachedAndDistinguishedFromAMiss(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60]);
        $calls = 0;
        $handler = static function () use (&$calls): mixed {
            ++$calls;

            return null;
        };

        $first = $interceptor->intercept($this->context('cachedTool'), $handler);
        $second = $interceptor->intercept($this->context('cachedTool'), $handler);

        Assert::null($first);
        Assert::null($second);
        Assert::same($calls, 1);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function context(string $toolName, array $arguments = [], ?string $clientId = 'client'): ToolCallContext
    {
        return new ToolCallContext(toolName: $toolName, arguments: $arguments, clientId: $clientId);
    }
}
