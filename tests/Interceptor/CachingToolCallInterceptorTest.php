<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Stateless\InputContext;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Mcp\Interceptor\CachingToolCallInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentity;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentityProviderInterface;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeCache;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeSession;
use Rasuvaeff\Yii3Mcp\Tests\Support\MutableExecutionIdentityProvider;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(CachingToolCallInterceptor::class)]
final class CachingToolCallInterceptorTest
{
    public function uncachedToolAlwaysCallsNext(): void
    {
        $interceptor = new CachingToolCallInterceptor($this->cache(), ttlSeconds: [], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['toolA' => 60, 'toolB' => 60], namespace: 'test-app');

        $first = $interceptor->intercept($this->context('toolA'), static fn(): string => 'from-a');
        $second = $interceptor->intercept($this->context('toolB'), static fn(): string => 'from-b');

        Assert::same($first, 'from-a');
        Assert::same($second, 'from-b');
    }

    public function clientIdAndToolNameCannotBeConfusedByConcatenation(): void
    {
        // without a separator, ('a','bc') and ('ab','c') concatenate to the
        // same "abc" — the key must keep them apart
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['bc' => 60, 'c' => 60], namespace: 'test-app');

        $first = $interceptor->intercept($this->context('bc', clientId: 'a'), static fn(): string => 'first');
        $second = $interceptor->intercept($this->context('c', clientId: 'ab'), static fn(): string => 'second');

        Assert::same($first, 'first');
        Assert::same($second, 'second');
    }

    public function allArgumentKeysAreConsideredNotJustTheFirst(): void
    {
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $cache = $this->cache();
        $interceptor = new CachingToolCallInterceptor($cache, ttlSeconds: ['cachedTool' => 42], namespace: 'test-app');

        $interceptor->intercept($this->context('cachedTool'), static fn(): string => 'x');

        verify(fn() => $cache->set(Arg::any(), Arg::any(), 42));
    }

    public function thrownExceptionsAreNeverCached(): void
    {
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $cache = $this->cache();
        when(fn() => $cache->get(Arg::any()))->throws(new RuntimeException('cache read failed'));
        $interceptor = new CachingToolCallInterceptor($cache, ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');

        Assert::same($interceptor->intercept($this->context('cachedTool'), static fn(): string => 'ok'), 'ok');
    }

    public function cacheReadFailureSkipsTheWriteBackToo(): void
    {
        $cache = $this->cache();
        when(fn() => $cache->get(Arg::any()))->throws(new RuntimeException('cache read failed'));
        $interceptor = new CachingToolCallInterceptor($cache, ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');

        $interceptor->intercept($this->context('cachedTool'), static fn(): string => 'ok');

        verify(fn() => $cache->set(Arg::any(), Arg::any(), Arg::any()), never: true);
    }

    public function cacheWriteFailureFailsOpen(): void
    {
        $cache = $this->cache();
        when(fn() => $cache->set(Arg::any(), Arg::any(), Arg::any()))->throws(new RuntimeException('cache write failed'));
        $interceptor = new CachingToolCallInterceptor($cache, ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');

        Assert::same($interceptor->intercept($this->context('cachedTool'), static fn(): string => 'ok'), 'ok');
    }

    public function distinctExecutionIdentitiesNeverShareACacheEntry(): void
    {
        // same client id, same tool, same arguments — only the delegated
        // identity differs; a shared entry would serve one end user's
        // upstream response to another
        $provider = new MutableExecutionIdentityProvider(new ExecutionIdentity(subjectId: 'user-1'));
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app', identityProvider: $provider);
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
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app', identityProvider: $provider);
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
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app', identityProvider: $provider);
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
        $cache = $this->cache();
        $provider = Understudy::for(ExecutionIdentityProviderInterface::class);
        when(fn() => $provider->current())->throws(new RuntimeException('identity resolution failed'));
        $interceptor = new CachingToolCallInterceptor($cache, ttlSeconds: ['cachedTool' => 60], namespace: 'test-app', identityProvider: $provider);
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
        verify(fn() => $cache->set(Arg::any(), Arg::any(), Arg::any()), never: true);
    }

    public function identityProviderFailureDoesNotAffectUncachedTools(): void
    {
        $provider = Understudy::for(ExecutionIdentityProviderInterface::class);
        when(fn() => $provider->current())->throws(new RuntimeException('identity resolution failed'));
        $interceptor = new CachingToolCallInterceptor($this->cache(), ttlSeconds: [], namespace: 'test-app', identityProvider: $provider);

        Assert::same($interceptor->intercept($this->context('otherTool'), static fn(): string => 'ok'), 'ok');
    }

    public function toolNameAndIdentityCannotBeConfusedByConcatenation(): void
    {
        // one server may cache identity-scoped and plain tools into one
        // PSR-16 store; a tool NAME that ends with what another call's
        // identity JSON looks like must not collapse into the same key
        $cache = $this->keyedCache();
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
        $cache = $this->cache();
        $keys = Arg::captor();
        $interceptor = new CachingToolCallInterceptor($cache, ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');

        $interceptor->intercept($this->context('cachedTool', ['id' => 1]), static fn(): string => 'x');

        verify(fn() => $cache->set($keys->capture(), Arg::any(), 60));
        $key = (string) $keys->last();

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
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
        $cache = $this->keyedCache();
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
            new CachingToolCallInterceptor($this->cache(), ttlSeconds: [], namespace: '');
        } catch (\InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('namespace');
    }

    public function nullResultIsCachedAndDistinguishedFromAMiss(): void
    {
        // the stored ['v' => null] wrapper on the second read is a HIT — the
        // wrapper is what tells a genuine null result apart from a miss
        $interceptor = new CachingToolCallInterceptor($this->keyedCache(), ttlSeconds: ['cachedTool' => 60], namespace: 'test-app');
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
    /**
     * A later round of a multi round-trip call carries answers its arguments
     * do not show: it must neither be served from the cache (that would skip
     * or replay the ask) nor stored.
     */
    public function aMultiRoundRetryBypassesTheCache(): void
    {
        $cache = new FakeCache();
        $cache->values = [];
        $interceptor = new CachingToolCallInterceptor($cache, ['order.delete' => 60], namespace: 'app');
        $retry = new ToolCallContext(
            toolName: 'order.delete',
            arguments: ['orderId' => '42'],
            session: new FakeSession([InputContext::class => new InputContext(['confirm' => ['action' => 'accept']])]),
        );
        $calls = 0;

        $interceptor->intercept($retry, static function () use (&$calls): string {
            $calls++;

            return 'deleted';
        });
        $interceptor->intercept($retry, static function () use (&$calls): string {
            $calls++;

            return 'deleted';
        });

        Assert::same($calls, 2);
        Assert::same($cache->values, []);
    }

    /**
     * An ask is not an outcome: cached, it would come back on the very retry
     * that carries its answer, forever.
     */
    public function anAskIsNeverCached(): void
    {
        $cache = new FakeCache();
        $interceptor = new CachingToolCallInterceptor($cache, ['order.delete' => 60], namespace: 'app');
        $ask = new InputRequiredResult(requestState: 'opaque');

        $result = $interceptor->intercept($this->context('order.delete'), static fn(): InputRequiredResult => $ask);

        Assert::same($result, $ask);
        Assert::same($cache->values, []);
    }

    private function context(string $toolName, array $arguments = [], ?string $clientId = 'client'): ToolCallContext
    {
        return new ToolCallContext(toolName: $toolName, arguments: $arguments, clientId: $clientId);
    }

    /**
     * An empty PSR-16 backend: an unstubbled get() answers null (a miss on
     * every key), exactly what the always-miss tests need.
     */
    private function cache(): CacheInterface
    {
        return Understudy::for(CacheInterface::class);
    }

    /**
     * A PSR-16 double backed by a real per-key store: set() records its
     * value under the exact key it was given, get() answers it back. Key
     * discrimination is the behaviour under test in the partitioning tests,
     * and a canned `when(...)->returns(null, ...)` sequence cannot express
     * "the second call misses BECAUSE its key differs" — it answers every
     * second read identically, key or not.
     */
    private function keyedCache(): CacheInterface
    {
        $cache = Understudy::for(CacheInterface::class);
        /** @var array<string, mixed> $store */
        $store = [];

        when(fn() => $cache->get(Arg::any()))->answers(
            static function (Invocation $call) use (&$store): mixed {
                return $store[(string) $call->arg('key')] ?? null;
            },
        );
        when(fn() => $cache->set(Arg::any(), Arg::any()))->answers(
            static function (Invocation $call) use (&$store): bool {
                /** @var mixed $value */
                $value = $call->arg('value');
                $store[(string) $call->arg('key')] = $value;

                return true;
            },
        );

        return $cache;
    }
}
