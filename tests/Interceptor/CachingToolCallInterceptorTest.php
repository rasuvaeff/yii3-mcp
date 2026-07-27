<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Rasuvaeff\Yii3Mcp\Interceptor\CachingToolCallInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentity;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeCache;
use Rasuvaeff\Yii3Mcp\Tests\Support\MutableExecutionIdentityProvider;
use Rasuvaeff\Yii3Mcp\Tests\Support\ThrowingExecutionIdentityProvider;
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
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: [], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['toolA' => 60, 'toolB' => 60], namespace: 'test-app');

        $first = $interceptor->intercept($this->context('toolA'), static fn(): string => 'from-a');
        $second = $interceptor->intercept($this->context('toolB'), static fn(): string => 'from-b');

        Assert::same($first, 'from-a');
        Assert::same($second, 'from-b');
    }

    public function clientIdAndToolNameCannotBeConfusedByConcatenation(): void
    {
        // without a separator, ('a','bc') and ('ab','c') concatenate to the
        // same "abc" — the key must keep them apart
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['bc' => 60, 'c' => 60], namespace: 'test-app');

        $first = $interceptor->intercept($this->context('bc', clientId: 'a'), static fn(): string => 'first');
        $second = $interceptor->intercept($this->context('c', clientId: 'ab'), static fn(): string => 'second');

        Assert::same($first, 'first');
        Assert::same($second, 'second');
    }

    public function allArgumentKeysAreConsideredNotJustTheFirst(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
        $calls = 0;
        $handler = static function () use (&$calls): int {
            ++$calls;

            return $calls;
        };

        $interceptor->intercept($this->context('cachedTool', clientId: 'client-a'), $handler);
        $interceptor->intercept($this->context('cachedTool', clientId: 'client-b'), $handler);

        Assert::same($calls, 2);
    }

    public function nullClientIdStillCachesUnderItsOwnPartition(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor($cache, ttlSeconds: ['cachedTool' => 42], namespace: 'test-app');

        $interceptor->intercept($this->context('cachedTool'), static fn(): string => 'x');

        Assert::same($cache->lastTtl, 42);
    }

    public function thrownExceptionsAreNeverCached(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor(new FakeCache(throwOnRead: true), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');

        Assert::same($interceptor->intercept($this->context('cachedTool'), static fn(): string => 'ok'), 'ok');
    }

    public function cacheReadFailureSkipsTheWriteBackToo(): void
    {
        $cache = new FakeCache(throwOnRead: true);
        $interceptor = new CachingToolCallInterceptor($cache, ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');

        $interceptor->intercept($this->context('cachedTool'), static fn(): string => 'ok');

        Assert::same($cache->values, []);
    }

    public function cacheWriteFailureFailsOpen(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(throwOnWrite: true), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');

        Assert::same($interceptor->intercept($this->context('cachedTool'), static fn(): string => 'ok'), 'ok');
    }

    public function distinctExecutionIdentitiesNeverShareACacheEntry(): void
    {
        // same client id, same tool, same arguments — only the delegated
        // identity differs; a shared entry would serve one end user's
        // upstream response to another
        $provider = new MutableExecutionIdentityProvider(new ExecutionIdentity(subjectId: 'user-1'));
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app', identityProvider: $provider);
        $calls = 0;
        $handler = static function () use (&$calls): int {
            ++$calls;

            return $calls;
        };

        $interceptor->intercept($this->context('cachedTool'), $handler);
        $provider->identity = new ExecutionIdentity(subjectId: 'user-2');
        $interceptor->intercept($this->context('cachedTool'), $handler);

        Assert::same($calls, 2);
    }

    public function everyIdentityFieldPartitionsTheCacheKey(): void
    {
        $provider = new MutableExecutionIdentityProvider(new ExecutionIdentity());
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app', identityProvider: $provider);
        $calls = 0;
        $handler = static function () use (&$calls): int {
            ++$calls;

            return $calls;
        };

        $interceptor->intercept($this->context('cachedTool'), $handler);
        $provider->identity = new ExecutionIdentity(tenantId: 'tenant-a');
        $interceptor->intercept($this->context('cachedTool'), $handler);
        $provider->identity = new ExecutionIdentity(clientId: 'app-1');
        $interceptor->intercept($this->context('cachedTool'), $handler);

        Assert::same($calls, 3);
    }

    public function sameExecutionIdentityIsServedFromCache(): void
    {
        $provider = new MutableExecutionIdentityProvider(new ExecutionIdentity(subjectId: 'user-1', tenantId: 'tenant-a'));
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app', identityProvider: $provider);
        $calls = 0;
        $handler = static function () use (&$calls): int {
            ++$calls;

            return $calls;
        };

        $interceptor->intercept($this->context('cachedTool'), $handler);
        $interceptor->intercept($this->context('cachedTool'), $handler);

        Assert::same($calls, 1);
    }

    public function identityProviderFailureFailsClosedForCachedTools(): void
    {
        // serving or storing a result without knowing whose it is would be
        // the exact cross-identity leak the key exists to prevent — unlike
        // a cache outage, this must NOT fail open
        $cache = new FakeCache();
        $interceptor = new CachingToolCallInterceptor($cache, ttlSeconds: ['cachedTool' => 60], namespace: 'test-app', identityProvider: new ThrowingExecutionIdentityProvider());
        $calls = 0;
        $caught = null;

        try {
            $interceptor->intercept($this->context('cachedTool'), static function () use (&$calls): int {
                return ++$calls;
            });
        } catch (RuntimeException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($calls, 0);
        Assert::same($cache->values, []);
    }

    public function identityProviderFailureDoesNotAffectUncachedTools(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: [], namespace: 'test-app', identityProvider: new ThrowingExecutionIdentityProvider());

        Assert::same($interceptor->intercept($this->context('otherTool'), static fn(): string => 'ok'), 'ok');
    }

    public function toolNameAndIdentityCannotBeConfusedByConcatenation(): void
    {
        // one server may cache identity-scoped and plain tools into one
        // PSR-16 store; a tool NAME that ends with what another call's
        // identity JSON looks like must not collapse into the same key
        $cache = new FakeCache();
        $withIdentity = new CachingToolCallInterceptor(
            $cache,
            ttlSeconds: ['t' => 60],
            namespace: 'test-app',
            identityProvider: new MutableExecutionIdentityProvider(new ExecutionIdentity()),
        );
        $plain = new CachingToolCallInterceptor($cache, ttlSeconds: ['t[null,null,null]' => 60], namespace: 'test-app');

        $first = $withIdentity->intercept($this->context('t'), static fn(): string => 'identity-scoped');
        $second = $plain->intercept($this->context('t[null,null,null]'), static fn(): string => 'plain');

        Assert::same($first, 'identity-scoped');
        Assert::same($second, 'plain');
    }

    public function cacheKeyIsPsr16SafeAndFormatStable(): void
    {
        $cache = new FakeCache();
        $interceptor = new CachingToolCallInterceptor($cache, ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');

        $interceptor->intercept($this->context('cachedTool', ['id' => 1]), static fn(): string => 'x');

        $key = (string) array_key_first($cache->values);

        // PSR-16 only guarantees keys up to 64 characters — a longer key
        // makes a strict cache throw on every call, silently disabling
        // caching; the format is pinned so an accidental change (which
        // orphans every deployed cache entry) fails a test, not silently
        Assert::same(strlen($key), 64);
        $material = json_encode([
            'v' => 2,
            'namespace' => 'test-app',
            'client' => 'client',
            'tool' => 'cachedTool',
            'identity' => null,
            'arguments' => ['id' => 1],
        ], JSON_THROW_ON_ERROR);
        Assert::same($key, 'yii3-mcp.toolcache.' . substr(hash('sha256', $material), 0, 45));
    }

    public function anonymousCallerNeverSharesAPartitionWithAClientNamedAnonymous(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
        $calls = 0;
        $handler = static function () use (&$calls): int {
            ++$calls;

            return $calls;
        };

        // a real client literally named "anonymous" must not read what an
        // identity-less (stdio) caller cached — absence is typed, not spelled
        $first = $interceptor->intercept($this->context('cachedTool', clientId: null), $handler);
        $second = $interceptor->intercept($this->context('cachedTool', clientId: 'anonymous'), $handler);

        Assert::same($first, 1);
        Assert::same($second, 2);
    }

    public function differentNamespacesNeverShareACacheEntry(): void
    {
        $cache = new FakeCache();
        $appA = new CachingToolCallInterceptor($cache, ttlSeconds: ['cachedTool' => 60], namespace: 'app-a');
        $appB = new CachingToolCallInterceptor($cache, ttlSeconds: ['cachedTool' => 60], namespace: 'app-b');
        $calls = 0;
        $handler = static function () use (&$calls): int {
            ++$calls;

            return $calls;
        };

        // two applications sharing one backend (a common Redis) with
        // same-named tools must never read each other's results
        $first = $appA->intercept($this->context('cachedTool'), $handler);
        $second = $appB->intercept($this->context('cachedTool'), $handler);

        Assert::same($first, 1);
        Assert::same($second, 2);
    }

    public function emptyNamespaceIsRejected(): void
    {
        $caught = null;

        try {
            new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: [], namespace: '');
        } catch (\InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('namespace');
    }

    public function nullResultIsCachedAndDistinguishedFromAMiss(): void
    {
        $interceptor = new CachingToolCallInterceptor(new FakeCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
