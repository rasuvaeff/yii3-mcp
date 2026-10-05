<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Captor;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\OpenApi\OpenApiBridgeFactory;
use Rasuvaeff\Yii3Mcp\OpenApi\OpenApiServerConfigurator;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

/**
 * The factory exists so a consumer outside `Rasuvaeff\` can assemble the
 * bridge without touching an `@internal` class. These tests therefore drive it
 * exactly as such a consumer would: only the factory and the `@api`
 * configurator it returns.
 */
#[Test]
#[Covers(OpenApiBridgeFactory::class)]
final class OpenApiBridgeFactoryTest
{
    public function buildsABridgeFromADecodedDocument(): void
    {
        [$client, $requests] = $this->trackingClient();

        $tester = $this->tester(OpenApiBridgeFactory::create(
            spec: OpenApiFixture::spec(),
            baseUrl: 'https://api.test/',
            httpClient: $client,
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
            operations: ['getBlogTags'],
        ));

        $tester->callTool('getBlogTags');

        Assert::same((string) $requests->last()->getUri(), 'https://api.test/rest/blog-tags');
    }

    public function buildsABridgeFromASpecFile(): void
    {
        [$client, $requests] = $this->trackingClient();
        $path = sys_get_temp_dir() . '/yii3-mcp-factory-spec-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));

        try {
            $tester = $this->tester(OpenApiBridgeFactory::create(
                spec: $path,
                baseUrl: 'https://api.test/',
                httpClient: $client,
                requestFactory: new Psr17Factory(),
                streamFactory: new Psr17Factory(),
                operations: ['getBlogTags'],
            ));

            $tester->callTool('getBlogTags');
        } finally {
            @unlink($path);
        }

        Assert::same((string) $requests->last()->getUri(), 'https://api.test/rest/blog-tags');
    }

    public function urlSpecKeepsItsOwnCredentialScopeAndUsesTheCache(): void
    {
        [$client, $requests] = $this->trackingClient(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));
        $cache = Understudy::for(CacheInterface::class);
        $keys = Arg::captor();

        $this->tester(OpenApiBridgeFactory::create(
            spec: 'https://spec.test/openapi.json',
            baseUrl: 'https://api.test/',
            httpClient: $client,
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
            operations: ['getBlogTags'],
            headers: ['X-Api-Token' => 'api-only'],
            specHeaders: ['X-Spec-Token' => 'spec-only'],
            specCache: $cache,
            specCacheTtl: 60,
        ))->callTool('getBlogTags');

        // the operation call carries the operation scope only…
        Assert::same($requests->last()->getHeaderLine('X-Api-Token'), 'api-only');
        Assert::same($requests->last()->getHeaderLine('X-Spec-Token'), '');

        // …and the URL document went through the PSR-16 cache with its TTL
        verify(fn() => $cache->set($keys->capture(), Arg::any(), 60));
    }

    /**
     * A TTL of zero means "fetch on every build" — passing a cache alongside
     * it must not start caching, or a spec change would go unnoticed for as
     * long as the entry lives.
     */
    public function urlSpecWithoutATtlNeverTouchesTheCache(): void
    {
        $cache = Understudy::for(CacheInterface::class);
        $client = $this->client(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));

        $this->tester(OpenApiBridgeFactory::create(
            spec: 'https://spec.test/openapi.json',
            baseUrl: 'https://api.test/',
            httpClient: $client,
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
            operations: ['getBlogTags'],
            specCache: $cache,
            specCacheTtl: 0,
        ))->callTool('getBlogTags');

        verify(fn() => $cache->set(Arg::any(), Arg::any(), Arg::any()), never: true);
    }

    public function multiSegmentPathParamsReachTheExecutor(): void
    {
        [$client, $requests] = $this->trackingClient();

        $this->tester(OpenApiBridgeFactory::create(
            spec: OpenApiFixture::spec(),
            baseUrl: 'https://api.test/',
            httpClient: $client,
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
            operations: ['getBlogTagBySlug'],
            multiSegmentPathParams: ['slug' => 3],
        ))->callTool('getBlogTagBySlug', ['slug' => 'dev/keppio']);

        Assert::same((string) $requests->last()->getUri(), 'https://api.test/rest/blog-tag/dev%2Fkeppio');
    }

    public function responseCapIsForwardedAndDefaultsToThePackageLimit(): void
    {
        $body = json_encode(['pad' => str_repeat('a', 512)], JSON_THROW_ON_ERROR);
        $cappedClient = $this->client(body: $body);
        $uncappedClient = $this->client(body: $body);

        $capped = $this->tester(OpenApiBridgeFactory::create(
            spec: OpenApiFixture::spec(),
            baseUrl: 'https://api.test/',
            httpClient: $cappedClient,
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
            operations: ['getBlogTags'],
            maxResponseBytes: 64,
        ))->callTool('getBlogTags');

        Assert::true($capped['isError']);
        Assert::string($capped['content'][0]['text'])->contains('64-byte limit');

        // the same body under the default cap goes through
        $passed = $this->tester(OpenApiBridgeFactory::create(
            spec: OpenApiFixture::spec(),
            baseUrl: 'https://api.test/',
            httpClient: $uncappedClient,
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
            operations: ['getBlogTags'],
        ))->callTool('getBlogTags');

        Assert::false($passed['isError'] ?? false);
    }

    public function opaqueErrorsSuppressTheUpstreamExcerpt(): void
    {
        $result = $this->tester(OpenApiBridgeFactory::create(
            spec: OpenApiFixture::spec(),
            baseUrl: 'https://api.test/',
            httpClient: $this->client(statusCode: 500, body: '{"secret":"upstream detail"}'),
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
            operations: ['getBlogTags'],
            opaqueErrors: true,
        ))->callTool('getBlogTags');

        Assert::true($result['isError']);
        Assert::string($result['content'][0]['text'])->notContains('upstream detail');
    }

    /**
     * A PSR-18 double answering every request with the same canned response.
     */
    private function client(int $statusCode = 200, string $body = '{"ok":true}'): ClientInterface
    {
        $client = Understudy::for(ClientInterface::class);
        when(fn() => $client->sendRequest(Arg::any()))
            ->returns(new Response($statusCode, ['Content-Type' => 'application/json'], $body));

        return $client;
    }

    /**
     * The same canned-response double, with the outgoing request captured for
     * URI/header assertions.
     *
     * @return array{ClientInterface, Captor<RequestInterface>}
     */
    private function trackingClient(int $statusCode = 200, string $body = '{"ok":true}'): array
    {
        $client = Understudy::for(ClientInterface::class);
        $requests = Arg::captor(RequestInterface::class);
        when(fn() => $client->sendRequest($requests->capture()))
            ->returns(new Response($statusCode, ['Content-Type' => 'application/json'], $body));

        return [$client, $requests];
    }

    private function tester(OpenApiServerConfigurator $configurator): McpTester
    {
        $psr17 = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([]),
            sessionStore: new InMemorySessionStore(),
            name: 'factory-test',
            version: '1.0.0',
        ))->create([], [$configurator]);

        return new McpTester($server, $psr17, $psr17, $psr17);
    }
}
