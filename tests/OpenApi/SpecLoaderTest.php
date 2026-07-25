<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecLoader;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeCache;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeHttpClient;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(SpecLoader::class)]
final class SpecLoaderTest
{
    public function fetchesAndIndexesSpecFromUrl(): void
    {
        $client = new FakeHttpClient(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));

        $index = $this->loader($client)->fromUrl('https://api.test/rest/json-url');

        Assert::same($index->get('getBlogTags')->operationId, 'getBlogTags');
        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/rest/json-url');
        Assert::same($client->lastRequest?->getMethod(), 'GET');
    }

    public function sendsConfiguredHeadersWithSpecRequest(): void
    {
        $client = new FakeHttpClient(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));

        $this->loader($client, headers: [
            'Authorization' => 'Bearer token-1',
            'Host' => 'app.local',
        ])->fromUrl('https://api.test/rest/json-url');

        Assert::same($client->lastRequest?->getHeaderLine('Authorization'), 'Bearer token-1');
        Assert::same($client->lastRequest?->getHeaderLine('Host'), 'app.local');
        Assert::same($client->lastRequest?->getHeaderLine('Accept'), 'application/json');
    }

    public function nonSuccessResponseThrowsWithStatusInMessage(): void
    {
        $loader = $this->loader(new FakeHttpClient(statusCode: 401, body: 'unauthorized'));

        $caught = null;

        try {
            $loader->fromUrl('https://api.test/rest/json-url');
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('HTTP 401');
    }

    public function malformedDocumentThrows(): void
    {
        $loader = $this->loader(new FakeHttpClient(body: '{broken'));

        Expect::exception(InvalidSpecException::class);

        $loader->fromUrl('https://api.test/rest/json-url');
    }

    public function malformedHttpDocumentIsNotCached(): void
    {
        $cache = new FakeCache();

        try {
            $this->loader(new FakeHttpClient(body: '{broken'), cache: $cache, cacheTtl: 60)
                ->fromUrl('https://api.test/openapi.json');
        } catch (InvalidSpecException) {
        }

        Assert::same($cache->values, []);
    }

    public function cachesRawDocumentWithConfiguredTtl(): void
    {
        $cache = new FakeCache();
        $firstClient = new FakeHttpClient(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));
        $secondClient = new FakeHttpClient(statusCode: 500);

        $this->loader($firstClient, cache: $cache, cacheTtl: 60)->fromUrl('https://api.test/openapi.json');
        $index = $this->loader($secondClient, cache: $cache, cacheTtl: 60)->fromUrl('https://api.test/openapi.json');

        Assert::same($index->get('getBlogTags')->operationId, 'getBlogTags');
        Assert::same($firstClient->requestCount, 1);
        Assert::same($secondClient->requestCount, 0);
        Assert::same($cache->lastTtl, 60);
    }

    public function zeroTtlPreservesFetchOnEveryLoad(): void
    {
        $cache = new FakeCache();
        $client = new FakeHttpClient(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));
        $loader = $this->loader($client, cache: $cache);

        $loader->fromUrl('https://api.test/openapi.json');
        $loader->fromUrl('https://api.test/openapi.json');

        Assert::same($client->requestCount, 2);
        Assert::same($cache->values, []);
    }

    public function urlAndHeaderScopeProduceDifferentCacheKeys(): void
    {
        $cache = new FakeCache();
        $body = json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR);

        $this->loader(new FakeHttpClient(body: $body), ['Authorization' => 'Bearer A'], $cache, 60)
            ->fromUrl('https://api.test/a');
        $this->loader(new FakeHttpClient(body: $body), ['authorization' => 'Bearer B'], $cache, 60)
            ->fromUrl('https://api.test/a');
        $this->loader(new FakeHttpClient(body: $body), ['Authorization' => 'Bearer A'], $cache, 60)
            ->fromUrl('https://api.test/b');

        Assert::same(count($cache->values), 3);

        foreach (array_keys($cache->values) as $key) {
            Assert::false(str_contains($key, 'Bearer'));
            Assert::false(str_contains($key, 'api.test'));
        }
    }

    public function cacheReadAndWriteFailuresFallBackToHttp(): void
    {
        $body = json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR);
        $readClient = new FakeHttpClient(body: $body);
        $writeClient = new FakeHttpClient(body: $body);

        $this->loader($readClient, cache: new FakeCache(throwOnRead: true), cacheTtl: 60)
            ->fromUrl('https://api.test/openapi.json');
        $this->loader($writeClient, cache: new FakeCache(throwOnWrite: true), cacheTtl: 60)
            ->fromUrl('https://api.test/openapi.json');

        Assert::same($readClient->requestCount, 1);
        Assert::same($writeClient->requestCount, 1);
    }

    public function malformedCachedDocumentFallsBackToHttp(): void
    {
        $cache = new FakeCache();
        $body = json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR);
        $client = new FakeHttpClient(body: $body);
        $loader = $this->loader($client, cache: $cache, cacheTtl: 60);
        $loader->fromUrl('https://api.test/openapi.json');
        $key = array_key_first($cache->values);
        Assert::true(is_string($key));
        $cache->values[$key] = '{broken';

        $index = $loader->fromUrl('https://api.test/openapi.json');

        Assert::same($client->requestCount, 2);
        Assert::same($index->get('getBlogTags')->operationId, 'getBlogTags');
    }

    public function expiredEntryIsFetchedAgain(): void
    {
        $cache = new FakeCache();
        $body = json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR);
        $firstClient = new FakeHttpClient(body: $body);
        $secondClient = new FakeHttpClient(body: $body);

        $this->loader($firstClient, cache: $cache, cacheTtl: 1)->fromUrl('https://api.test/openapi.json');
        $cache->clear();
        $this->loader($secondClient, cache: $cache, cacheTtl: 1)->fromUrl('https://api.test/openapi.json');

        Assert::same($secondClient->requestCount, 1);
    }

    public function negativeTtlIsRejected(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        $this->loader(new FakeHttpClient(), cacheTtl: -1);
    }

    /**
     * @param array<string, string> $headers
     */
    private function loader(
        FakeHttpClient $client,
        array $headers = [],
        ?FakeCache $cache = null,
        int $cacheTtl = 0,
    ): SpecLoader {
        return new SpecLoader(
            httpClient: $client,
            requestFactory: new Psr17Factory(),
            headers: $headers,
            cache: $cache,
            cacheTtl: $cacheTtl,
        );
    }
}
