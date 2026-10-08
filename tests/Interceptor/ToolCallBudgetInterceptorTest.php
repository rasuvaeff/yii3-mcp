<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use InvalidArgumentException;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Stateless\RequestMeta;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallBudgetInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeCache;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeSession;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(ToolCallBudgetInterceptor::class)]
final class ToolCallBudgetInterceptorTest
{
    public function allowsCallsWithinTheBudget(): void
    {
        $tester = $this->tester(budget: 2);

        Assert::same($tester->callTool('greet', ['name' => 'One'])['content'][0]['text'], 'Hello, One!');
        Assert::same($tester->callTool('greet', ['name' => 'Two'])['content'][0]['text'], 'Hello, Two!');
    }

    public function exhaustedBudgetBecomesToolErrorEnvelope(): void
    {
        $tester = $this->tester(budget: 2);
        $tester->callTool('greet', ['name' => 'One']);
        $tester->callTool('greet', ['name' => 'Two']);

        $result = $tester->callTool('greet', ['name' => 'Three']);

        Assert::true($result['isError']);
        Assert::string($result['content'][0]['text'])->contains('budget of 2 is exhausted');
    }

    public function freshSessionStartsAFreshCounter(): void
    {
        $server = $this->server(budget: 1);
        $factory = new Psr17Factory();

        $first = new McpTester($server, $factory, $factory, $factory);
        $first->callTool('greet', ['name' => 'One']);
        Assert::true($first->callTool('greet', ['name' => 'Two'])['isError']);

        $second = new McpTester($server, $factory, $factory, $factory);
        $result = $second->callTool('greet', ['name' => 'Three']);

        Assert::same($result['content'][0]['text'], 'Hello, Three!');
    }

    public function passesThroughWithoutASession(): void
    {
        $interceptor = new ToolCallBudgetInterceptor(budget: 1);
        $context = new ToolCallContext(toolName: 'x', arguments: []);

        Assert::same($interceptor->intercept($context, static fn(): string => 'a'), 'a');
        Assert::same($interceptor->intercept($context, static fn(): string => 'b'), 'b');
    }

    public function corruptedCounterIsTreatedAsZero(): void
    {
        $interceptor = new ToolCallBudgetInterceptor(budget: 1);
        $session = new FakeSession(['rasuvaeff.yii3-mcp.tool-calls' => 'garbage']);
        $context = new ToolCallContext(toolName: 'x', arguments: [], session: $session);

        Assert::same($interceptor->intercept($context, static fn(): string => 'ok'), 'ok');
        Assert::same($session->get('rasuvaeff.yii3-mcp.tool-calls'), 1);
    }

    public function countsTheAttemptBeforeExecuting(): void
    {
        $interceptor = new ToolCallBudgetInterceptor(budget: 5);
        $session = new FakeSession();
        $context = new ToolCallContext(toolName: 'x', arguments: [], session: $session);

        $interceptor->intercept($context, static fn(): string => 'ok');
        $interceptor->intercept($context, static fn(): string => 'ok');

        Assert::same($session->get('rasuvaeff.yii3-mcp.tool-calls'), 2);
    }

    public function failedCallStillConsumesTheBudget(): void
    {
        $interceptor = new ToolCallBudgetInterceptor(budget: 5);
        $session = new FakeSession();
        $context = new ToolCallContext(toolName: 'x', arguments: [], session: $session);

        $caught = null;

        try {
            $interceptor->intercept($context, static fn(): string => throw new RuntimeException('downstream failed'));
        } catch (RuntimeException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($session->get('rasuvaeff.yii3-mcp.tool-calls'), 1);
    }

    #[DataProvider('invalidBudgetProvider')]
    public function throwsOnNonPositiveBudget(int $budget): void
    {
        $caught = null;

        try {
            new ToolCallBudgetInterceptor($budget);
        } catch (InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('at least 1');
    }

    public static function invalidBudgetProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
    }

    /**
     * The stateless era hands every request a throwaway session: a session
     * counter would start at zero on each call and never run out. End to end
     * through the real SDK path, not a hand-built context.
     */
    public function statelessEraCannotOutrunTheBudget(): void
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
            name: 'budget-suite',
            version: '1.0.0',
            modernEra: true,
        ))->create([GreetingTool::class], [], [new ToolCallBudgetInterceptor(budget: 2, cache: new FakeCache())]);
        $tester = new McpTester($server, $factory, $factory, $factory, ProtocolVersion::V2026_07_28);

        $tester->callTool('greet', ['name' => 'One']);
        $tester->callTool('greet', ['name' => 'Two']);
        $result = $tester->callTool('greet', ['name' => 'Three']);

        Assert::true($result['isError']);
        Assert::string($result['content'][0]['text'])->contains('budget of 2 per 3600 seconds is exhausted');
    }

    public function statelessBudgetIsPerClient(): void
    {
        $interceptor = new ToolCallBudgetInterceptor(budget: 1, cache: new FakeCache(), clock: static fn(): int => 1000);

        Assert::same($this->modernCall($interceptor, 'alice'), 'ran');
        Assert::same($this->modernCall($interceptor, 'alice'), 'rejected');
        Assert::same($this->modernCall($interceptor, 'bob'), 'ran');
    }

    /**
     * Without a client id there is nothing to tell callers apart by: every
     * anonymous caller draws from one budget — the stricter direction.
     */
    public function anonymousStatelessCallersShareOneBudget(): void
    {
        $interceptor = new ToolCallBudgetInterceptor(budget: 1, cache: new FakeCache(), clock: static fn(): int => 1000);

        Assert::same($this->modernCall($interceptor, null), 'ran');
        Assert::same($this->modernCall($interceptor, null), 'rejected');
        // a real client id named like an absent one does not land in that bucket
        Assert::same($this->modernCall($interceptor, ''), 'ran');
    }

    /**
     * The window is absolute: a client that keeps calling is reset when the
     * window turns, and the TTL written runs to the END of the window, never
     * re-armed past it by the next write.
     */
    public function statelessWindowTurnsOnTheClockNotOnTheLastWrite(): void
    {
        $now = 3_590;
        $cache = new FakeCache();
        $interceptor = new ToolCallBudgetInterceptor(budget: 1, cache: $cache, window: 3_600, clock: static function () use (&$now): int {
            return $now;
        });

        Assert::same($this->modernCall($interceptor, 'alice'), 'ran');
        Assert::same($cache->lastTtl, 10);
        Assert::same($this->modernCall($interceptor, 'alice'), 'rejected');

        $now = 3_600;

        Assert::same($this->modernCall($interceptor, 'alice'), 'ran');
        Assert::same($cache->lastTtl, 3_600);
    }

    public function exhaustedStatelessBudgetNamesTheRetryDelay(): void
    {
        $interceptor = new ToolCallBudgetInterceptor(budget: 1, cache: new FakeCache(), window: 60, clock: static fn(): int => 125);
        $this->modernCall($interceptor, 'alice');

        Assert::same($this->modernError($interceptor, 'alice'), 'Tool-call budget of 1 per 60 seconds is exhausted; retry in 55 seconds');
    }

    /**
     * Fail-closed, like RateLimitInterceptor: an outage must not turn the
     * guard into "unlimited".
     */
    #[DataProvider('unavailableCacheProvider')]
    public function statelessCallIsRejectedWhenTheBudgetCannotBeCounted(?FakeCache $cache): void
    {
        $interceptor = new ToolCallBudgetInterceptor(budget: 5, cache: $cache);

        Assert::string($this->modernError($interceptor, 'alice'))->contains('budget is unavailable');
    }

    public static function unavailableCacheProvider(): iterable
    {
        yield 'no cache configured' => [null];
        yield 'read fails' => [new FakeCache(throwOnRead: true)];
        yield 'write fails' => [new FakeCache(throwOnWrite: true)];
    }

    public function namespacesIsolateServersOnOneCache(): void
    {
        $cache = new FakeCache();
        $clock = static fn(): int => 1000;

        $this->modernCall(new ToolCallBudgetInterceptor(budget: 1, cache: $cache, namespace: 'app-a', clock: $clock), 'alice');

        Assert::same($this->modernCall(new ToolCallBudgetInterceptor(budget: 1, cache: $cache, namespace: 'app-b', clock: $clock), 'alice'), 'ran');
    }

    public function budgetKeysFitPsr16AndNeverCollideWithResultCache(): void
    {
        $cache = new FakeCache();
        $this->modernCall(new ToolCallBudgetInterceptor(budget: 1, cache: $cache), 'alice');

        $key = (string) array_key_first($cache->values);

        Assert::true(strlen($key) <= 64);
        Assert::true(str_starts_with($key, 'yii3-mcp.budget.'));
    }

    public function throwsOnNonPositiveWindow(): void
    {
        $caught = null;

        try {
            new ToolCallBudgetInterceptor(budget: 1, window: 0);
        } catch (InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('window must be at least 1 second');
    }

    /**
     * The stateless call is counted in the cache ONLY — falling through into
     * the session counter as well would count it twice the moment a session
     * survived.
     */
    public function statelessCallLeavesTheSessionAlone(): void
    {
        $context = $this->modernContext('alice');
        $interceptor = new ToolCallBudgetInterceptor(budget: 5, cache: new FakeCache());

        Assert::same($interceptor->intercept($context, static fn(): string => 'ran'), 'ran');
        Assert::null($context->session?->get('rasuvaeff.yii3-mcp.tool-calls'));
    }

    public function missingCacheNamesTheReason(): void
    {
        Assert::same(
            $this->modernError(new ToolCallBudgetInterceptor(budget: 5), 'alice'),
            'Tool-call budget is unavailable: no cache is configured for the stateless protocol era',
        );
    }

    public function oneSecondWindowIsAccepted(): void
    {
        $interceptor = new ToolCallBudgetInterceptor(budget: 1, cache: new FakeCache(), window: 1, clock: static fn(): int => 10);

        Assert::same($this->modernCall($interceptor, 'alice'), 'ran');
    }

    /**
     * The key format is part of the contract: a change silently resets every
     * running budget, so it moves only with KEY_FORMAT_VERSION.
     */
    public function budgetKeyFormatIsStable(): void
    {
        $cache = new FakeCache();
        $this->modernCall(new ToolCallBudgetInterceptor(budget: 1, cache: $cache, window: 60, namespace: 'app', clock: static fn(): int => 125), 'alice');

        $expected = 'yii3-mcp.budget.' . substr(hash('sha256', json_encode([
            'v' => 1,
            'namespace' => 'app',
            'client' => 'alice',
            'window' => 60,
            'bucket' => 2,
        ], JSON_THROW_ON_ERROR)), 0, 45);

        Assert::same(array_keys($cache->values), [$expected]);
    }

    /**
     * Whatever sits under the key that is not a count (a foreign writer, a
     * corrupted entry) reads as an unused budget — not as one already spent
     * and not as credit.
     */
    public function aCorruptedCounterReadsAsUnused(): void
    {
        $cache = new FakeCache();
        $interceptor = new ToolCallBudgetInterceptor(budget: 1, cache: $cache, clock: static fn(): int => 1000);
        $this->modernCall($interceptor, 'alice');
        $key = (string) array_key_first($cache->values);
        $cache->values[$key] = 'corrupted';

        Assert::same($this->modernCall($interceptor, 'alice'), 'ran');
        Assert::same($this->modernCall($interceptor, 'alice'), 'rejected');
    }

    private function modernCall(ToolCallBudgetInterceptor $interceptor, ?string $clientId): string
    {
        try {
            return (string) $interceptor->intercept($this->modernContext($clientId), static fn(): string => 'ran');
        } catch (ToolCallException) {
            return 'rejected';
        }
    }

    private function modernError(ToolCallBudgetInterceptor $interceptor, ?string $clientId): string
    {
        try {
            $interceptor->intercept($this->modernContext($clientId), static fn(): string => 'ran');
        } catch (ToolCallException $e) {
            return $e->getMessage();
        }

        return 'ran';
    }

    /**
     * What the SDK hands a stateless call: a fresh session carrying the
     * request's _meta as RequestMeta.
     */
    private function modernContext(?string $clientId): ToolCallContext
    {
        return new ToolCallContext(
            toolName: 'greet',
            arguments: [],
            session: new FakeSession([RequestMeta::class => new RequestMeta('2026-07-28', new ClientCapabilities())]),
            clientId: $clientId,
        );
    }

    private function tester(int $budget): McpTester
    {
        $factory = new Psr17Factory();

        return new McpTester(
            server: $this->server($budget),
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    private function server(int $budget): Server
    {
        return (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
            name: 'budget-suite',
            version: '1.0.0',
        ))->create([GreetingTool::class], [], [new ToolCallBudgetInterceptor($budget)]);
    }
}
