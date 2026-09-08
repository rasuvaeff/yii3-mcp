<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\OpenApi\OpenApiBridgeFactory;
use Rasuvaeff\Yii3Mcp\OpenApi\OpenApiServerConfigurator;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeCache;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeHttpClient;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

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
        $client = new FakeHttpClient();

        $tester = $this->tester(OpenApiBridgeFactory::create(
            spec: OpenApiFixture::spec(),
            baseUrl: 'https://api.test/',
            httpClient: $client,
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
            operations: ['getBlogTags'],
        ));

        $tester->callTool('getBlogTags');

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/rest/blog-tags');
    }

    public function buildsABridgeFromASpecFile(): void
    {
        $client = new FakeHttpClient();
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

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/rest/blog-tags');
    }

    public function urlSpecKeepsItsOwnCredentialScopeAndUsesTheCache(): void
    {
        $client = new FakeHttpClient(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));
        $cache = new FakeCache();

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

        Assert::same($client->lastRequest?->getHeaderLine('X-Api-Token'), 'api-only');
        Assert::same($client->lastRequest?->getHeaderLine('X-Spec-Token'), '');
        Assert::same($cache->lastTtl, 60);
        Assert::same(count($cache->values), 1);
    }

    /**
     * A TTL of zero means "fetch on every build" — passing a cache alongside
     * it must not start caching, or a spec change would go unnoticed for as
     * long as the entry lives.
     */
    public function urlSpecWithoutATtlNeverTouchesTheCache(): void
    {
        $cache = new FakeCache();

        $this->tester(OpenApiBridgeFactory::create(
            spec: 'https://spec.test/openapi.json',
            baseUrl: 'https://api.test/',
            httpClient: new FakeHttpClient(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR)),
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
            operations: ['getBlogTags'],
            specCache: $cache,
            specCacheTtl: 0,
        ))->callTool('getBlogTags');

        Assert::same($cache->values, []);
    }

    public function multiSegmentPathParamsReachTheExecutor(): void
    {
        $client = new FakeHttpClient();

        $this->tester(OpenApiBridgeFactory::create(
            spec: OpenApiFixture::spec(),
            baseUrl: 'https://api.test/',
            httpClient: $client,
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
            operations: ['getBlogTagBySlug'],
            multiSegmentPathParams: ['slug' => 3],
        ))->callTool('getBlogTagBySlug', ['slug' => 'dev/keppio']);

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/rest/blog-tag/dev%2Fkeppio');
    }

    public function responseCapIsForwardedAndDefaultsToThePackageLimit(): void
    {
        $body = json_encode(['pad' => str_repeat('a', 512)], JSON_THROW_ON_ERROR);

        $capped = $this->tester(OpenApiBridgeFactory::create(
            spec: OpenApiFixture::spec(),
            baseUrl: 'https://api.test/',
            httpClient: new FakeHttpClient(body: $body),
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
            httpClient: new FakeHttpClient(body: $body),
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
            httpClient: new FakeHttpClient(statusCode: 500, body: '{"secret":"upstream detail"}'),
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
            operations: ['getBlogTags'],
            opaqueErrors: true,
        ))->callTool('getBlogTags');

        Assert::true($result['isError']);
        Assert::string($result['content'][0]['text'])->notContains('upstream detail');
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
