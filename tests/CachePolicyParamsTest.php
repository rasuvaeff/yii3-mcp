<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use InvalidArgumentException;
use Mcp\Schema\Enum\CacheScope;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Wire\CachePolicy;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\CachePolicyParams;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyListVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(CachePolicyParams::class)]
#[Covers(McpServerFactory::class)]
final class CachePolicyParamsTest
{
    public function defaultsMeanTheSdkDefault(): void
    {
        Assert::null(CachePolicyParams::parse([]));
        Assert::null(CachePolicyParams::parse(['ttl_ms' => 0, 'scope' => 'private', 'methods' => []]));
    }

    public function aDefaultAndPerMethodOverridesAreKept(): void
    {
        $policy = CachePolicyParams::parse([
            'ttl_ms' => 1000,
            'methods' => ['tools/list' => ['ttl_ms' => 300000, 'scope' => 'public']],
        ]);

        Assert::instanceOf($policy, CachePolicy::class);
        Assert::same($policy?->ttlFor('tools/list'), 300000);
        Assert::same($policy?->scopeFor('tools/list'), CacheScope::Public);
        Assert::same($policy?->ttlFor('prompts/list'), 1000);
        Assert::same($policy?->scopeFor('prompts/list'), CacheScope::Private);
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('invalidProvider')]
    public function anythingItWouldIgnoreFailsAtConfigLoad(array $params, string $message): void
    {
        try {
            CachePolicyParams::parse($params);
            $error = 'accepted';
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        Assert::string($error)->contains($message);
    }

    public static function invalidProvider(): iterable
    {
        yield 'negative ttl' => [['ttl_ms' => -1], 'cache_policy.ttl_ms must be an integer'];
        yield 'string ttl' => [['ttl_ms' => '60'], 'cache_policy.ttl_ms must be an integer'];
        yield 'unknown scope' => [['scope' => 'shared'], 'cache_policy.scope must be "private" or "public"'];
        yield 'misspelled method' => [['methods' => ['tool/list' => ['ttl_ms' => 1]]], '"tool/list" carries no caching hint'];
        yield 'non-cacheable method' => [['methods' => ['tools/call' => ['ttl_ms' => 1]]], '"tools/call" carries no caching hint'];
        yield 'method override not a map' => [['methods' => ['tools/list' => 60]], 'cache_policy.methods.tools/list must be'];
        yield 'methods not a map' => [['methods' => 'tools/list'], 'cache_policy.methods must be a map'];
    }

    /**
     * A visibility filter makes lists per caller: `public` would let a shared
     * cache serve one caller's filtered tools/list to the next.
     */
    public function publicHintOnAFilteredListFailsTheBuild(): void
    {
        $policy = CachePolicy::default(0)->withMethod('tools/list', 60000, CacheScope::Public);

        try {
            $this->factory($policy)->create([GreetingTool::class], [], [], new DenyListVisibility(hidden: ['explode']));
            $error = 'built';
        } catch (\LogicException $e) {
            $error = $e->getMessage();
        }

        Assert::string($error)->contains('cache_policy marks tools/list as "public"');
    }

    public function publicHintWithoutVisibilityIsTheOperatorsCall(): void
    {
        $policy = CachePolicy::default(60000, CacheScope::Public);

        $this->factory($policy)->create([GreetingTool::class]);

        Assert::same(CachePolicyParams::publiclyCachedCallerSpecificMethods($policy), CachePolicyParams::CALLER_SPECIFIC_METHODS);
    }

    /**
     * End to end: a stateless tools/list carries the configured hint; with no
     * policy it carries the SDK's "nothing fresh, nothing shared".
     */
    public function statelessAnswersCarryTheHints(): void
    {
        $psr17 = new Psr17Factory();
        $configured = new McpTester(
            $this->factory(CachePolicy::default(0)->withMethod('tools/list', 300000))->create([GreetingTool::class]),
            $psr17,
            $psr17,
            $psr17,
            ProtocolVersion::V2026_07_28,
        );
        $default = new McpTester(
            (new McpServerFactory(container: new SimpleContainer([]), sessionStore: new InMemorySessionStore()))->create([GreetingTool::class]),
            $psr17,
            $psr17,
            $psr17,
            ProtocolVersion::V2026_07_28,
        );

        $hinted = $configured->request('tools/list');
        $plain = $default->request('tools/list');

        Assert::same([$hinted['ttlMs'] ?? null, $hinted['cacheScope'] ?? null], [300000, 'private']);
        Assert::same([$plain['ttlMs'] ?? null, $plain['cacheScope'] ?? null], [0, 'private']);
    }

    public function publicWithZeroTtlCachesNothing(): void
    {
        Assert::same(CachePolicyParams::publiclyCachedCallerSpecificMethods(CachePolicy::default(0, CacheScope::Public)), []);
    }

    private function factory(CachePolicy $policy): McpServerFactory
    {
        return new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hi')]),
            sessionStore: new InMemorySessionStore(),
            cachePolicy: $policy,
        );
    }
}
