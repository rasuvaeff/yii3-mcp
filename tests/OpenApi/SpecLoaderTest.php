<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Captor;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecLoader;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Rasuvaeff\Yii3Mcp\Tests\Support\StubStream;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(SpecLoader::class)]
final class SpecLoaderTest
{
    public function fetchesAndIndexesSpecFromUrl(): void
    {
        [$client, $requests] = $this->trackingClient(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));

        $index = $this->loader($client)->fromUrl('https://api.test/rest/json-url');

        Assert::same($index->get('getBlogTags')->operationId, 'getBlogTags');
        Assert::same((string) $requests->last()->getUri(), 'https://api.test/rest/json-url');
        Assert::same($requests->last()->getMethod(), 'GET');
    }

    public function sendsConfiguredHeadersWithSpecRequest(): void
    {
        [$client, $requests] = $this->trackingClient(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));

        $this->loader($client, headers: [
            'Authorization' => 'Bearer token-1',
            'Host' => 'app.local',
        ])->fromUrl('https://api.test/rest/json-url');

        Assert::same($requests->last()->getHeaderLine('Authorization'), 'Bearer token-1');
        Assert::same($requests->last()->getHeaderLine('Host'), 'app.local');
        Assert::same($requests->last()->getHeaderLine('Accept'), 'application/json');
    }

    public function nonSuccessResponseThrowsWithStatusInMessage(): void
    {
        $loader = $this->loader($this->client(statusCode: 401, body: 'unauthorized'));

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
        $loader = $this->loader($this->client(body: '{broken'));

        Expect::exception(InvalidSpecException::class);

        $loader->fromUrl('https://api.test/rest/json-url');
    }

    public function malformedHttpDocumentIsNotCached(): void
    {
        $cache = Understudy::for(CacheInterface::class);

        try {
            $this->loader($this->client(body: '{broken'), cache: $cache, cacheTtl: 60)
                ->fromUrl('https://api.test/openapi.json');
        } catch (InvalidSpecException) {
        }

        verify(fn() => $cache->set(Arg::any(), Arg::any(), Arg::any()), never: true);
    }

    public function cachesRawDocumentWithConfiguredTtl(): void
    {
        $body = json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR);
        $firstClient = $this->client(body: $body);
        $secondClient = $this->client(statusCode: 500, body: 'upstream is down');
        $cache = Understudy::for(CacheInterface::class);
        // the first load misses and stores; the second serves the stored document
        when(fn() => $cache->get(Arg::any()))->returns(null, $body);

        $this->loader($firstClient, cache: $cache, cacheTtl: 60)->fromUrl('https://api.test/openapi.json');
        $index = $this->loader($secondClient, cache: $cache, cacheTtl: 60)->fromUrl('https://api.test/openapi.json');

        Assert::same($index->get('getBlogTags')->operationId, 'getBlogTags');
        verify(fn() => $firstClient->sendRequest(Arg::any()));
        verify(fn() => $secondClient->sendRequest(Arg::any()), never: true);
        verify(fn() => $cache->set(Arg::any(), Arg::any(), 60));
    }

    public function zeroTtlPreservesFetchOnEveryLoad(): void
    {
        $cache = Understudy::for(CacheInterface::class);
        $client = $this->client(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));
        $loader = $this->loader($client, cache: $cache);

        $loader->fromUrl('https://api.test/openapi.json');
        $loader->fromUrl('https://api.test/openapi.json');

        verify(fn() => $client->sendRequest(Arg::any()), times: 2);
        verify(fn() => $cache->set(Arg::any(), Arg::any(), Arg::any()), never: true);
    }

    public function zeroTtlIgnoresAPreviouslyCachedDocument(): void
    {
        $body = json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR);
        $cache = Understudy::for(CacheInterface::class);
        // the first read (the cached loader) misses; a SECOND read of the
        // same key would answer the stored document — the ttl-0 loader must
        // refuse to even ask, or it would be served from the cache
        when(fn() => $cache->get(Arg::any()))->returns(null, $body);

        // seed the cache via a normal, cached load
        $this->loader($this->client(body: $body), cache: $cache, cacheTtl: 60)
            ->fromUrl('https://api.test/openapi.json');
        verify(fn() => $cache->set(Arg::any(), Arg::any(), 60));

        // a SECOND loader configured with cacheTtl: 0 must still hit HTTP,
        // ignoring the value the first loader just cached under the same key
        $freshClient = $this->client(body: $body);
        $this->loader($freshClient, cache: $cache, cacheTtl: 0)
            ->fromUrl('https://api.test/openapi.json');

        verify(fn() => $freshClient->sendRequest(Arg::any()));
    }

    public function urlAndHeaderScopeProduceDifferentCacheKeys(): void
    {
        $body = json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR);
        $cache = Understudy::for(CacheInterface::class);
        $keys = Arg::captor();

        $this->loader($this->client(body: $body), ['Authorization' => 'Bearer A'], $cache, 60)
            ->fromUrl('https://api.test/a');
        $this->loader($this->client(body: $body), ['authorization' => 'Bearer B'], $cache, 60)
            ->fromUrl('https://api.test/a');
        $this->loader($this->client(body: $body), ['Authorization' => 'Bearer A'], $cache, 60)
            ->fromUrl('https://api.test/b');

        verify(fn() => $cache->set($keys->capture(), Arg::any(), 60), times: 3);

        /** @var list<string> $storedKeys */
        $storedKeys = array_map(strval(...), $keys->all());

        Assert::same(count(array_unique($storedKeys)), 3);

        foreach ($storedKeys as $key) {
            Assert::false(str_contains($key, 'Bearer'));
            Assert::false(str_contains($key, 'api.test'));
        }
    }

    public function headerNameCasingAndInsertionOrderDoNotAffectTheCacheKey(): void
    {
        $body = json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR);
        $firstKeys = Arg::captor();
        $secondKeys = Arg::captor();
        $cache1 = Understudy::for(CacheInterface::class);
        $cache2 = Understudy::for(CacheInterface::class);

        $this->loader($this->client(body: $body), ['X-Api-Key' => 'k', 'Accept' => 'json'], $cache1, 60)
            ->fromUrl('https://api.test/a');
        $this->loader($this->client(body: $body), ['accept' => 'json', 'x-api-key' => 'k'], $cache2, 60)
            ->fromUrl('https://api.test/a');

        verify(fn() => $cache1->set($firstKeys->capture(), Arg::any(), 60));
        verify(fn() => $cache2->set($secondKeys->capture(), Arg::any(), 60));
        Assert::same($firstKeys->last(), $secondKeys->last());
    }

    public function cacheKeyIsPsr16SafeAndFormatStable(): void
    {
        $cache = Understudy::for(CacheInterface::class);
        $keys = Arg::captor();

        $this->loader($this->client(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR)), cache: $cache, cacheTtl: 60)
            ->fromUrl('https://api.test/openapi.json');

        verify(fn() => $cache->set($keys->capture(), Arg::any(), 60));
        $key = (string) $keys->last();

        // PSR-16 only guarantees keys up to 64 characters — a longer key
        // makes a strict cache throw on every call, silently disabling
        // caching; the format is pinned so an accidental change (which
        // orphans every deployed cache entry) fails a test, not silently.
        Assert::same(strlen($key), 64);
        Assert::same(
            $key,
            'yii3-mcp.openapi.' . substr(hash('sha256', 'https://api.test/openapi.json' . "\0" . '[]'), 0, 47),
        );
    }

    public function cacheReadAndWriteFailuresFallBackToHttp(): void
    {
        $body = json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR);
        $readClient = $this->client(body: $body);
        $writeClient = $this->client(body: $body);
        $readCache = Understudy::for(CacheInterface::class);
        $writeCache = Understudy::for(CacheInterface::class);
        when(fn() => $readCache->get(Arg::any()))->throws(new RuntimeException('cache read failed'));
        when(fn() => $writeCache->set(Arg::any(), Arg::any(), Arg::any()))->throws(new RuntimeException('cache write failed'));

        $this->loader($readClient, cache: $readCache, cacheTtl: 60)
            ->fromUrl('https://api.test/openapi.json');
        $this->loader($writeClient, cache: $writeCache, cacheTtl: 60)
            ->fromUrl('https://api.test/openapi.json');

        verify(fn() => $readClient->sendRequest(Arg::any()));
        verify(fn() => $writeClient->sendRequest(Arg::any()));
    }

    public function malformedCachedDocumentFallsBackToHttp(): void
    {
        $body = json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR);
        $client = $this->client(body: $body);
        $cache = Understudy::for(CacheInterface::class);
        // the first load stores a valid document; the second reads a broken one
        when(fn() => $cache->get(Arg::any()))->returns(null, '{broken');
        $loader = $this->loader($client, cache: $cache, cacheTtl: 60);

        $loader->fromUrl('https://api.test/openapi.json');
        $index = $loader->fromUrl('https://api.test/openapi.json');

        verify(fn() => $client->sendRequest(Arg::any()), times: 2);
        Assert::same($index->get('getBlogTags')->operationId, 'getBlogTags');
    }

    public function expiredEntryIsFetchedAgain(): void
    {
        $body = json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR);
        $firstClient = $this->client(body: $body);
        $secondClient = $this->client(body: $body);
        // an empty backend: every read misses, the seeded entry included
        $cache = Understudy::for(CacheInterface::class);

        $this->loader($firstClient, cache: $cache, cacheTtl: 1)->fromUrl('https://api.test/openapi.json');
        $this->loader($secondClient, cache: $cache, cacheTtl: 1)->fromUrl('https://api.test/openapi.json');

        verify(fn() => $secondClient->sendRequest(Arg::any()));
    }

    public function negativeTtlIsRejected(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        $this->loader($this->client(), cacheTtl: -1);
    }

    /**
     * @param array<string, string> $headers
     */
    public function specUrlWithEmbeddedCredentialsIsRejected(): void
    {
        $client = $this->client();
        $caught = null;

        try {
            $this->loader($client)->fromUrl('https://token:hunter2@api.test/openapi.json');
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('must not embed credentials');
        // the credential-bearing URL is never fetched and never echoed back
        verify(fn() => $client->sendRequest(Arg::any()), never: true);
        Assert::false(str_contains($caught->getMessage(), 'hunter2'));
    }

    public function oversizedSpecResponseIsRejected(): void
    {
        $body = '{"paths":{"pad":"' . str_repeat('a', SpecIndex::MAX_DOCUMENT_BYTES) . '"}}';
        $caught = null;

        try {
            $this->loader($this->client(body: $body))->fromUrl('https://api.test/openapi.json');
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        // the advertised-size rejection names the size it refused — proof it
        // fired before the read, not the generic mid-read message
        Assert::string($caught->getMessage())->contains(sprintf('of %d bytes exceeds', strlen($body)));
    }

    public function specExactlyAtTheDocumentLimitParses(): void
    {
        $index = $this->loader($this->client(body: $this->specPaddedTo(SpecIndex::MAX_DOCUMENT_BYTES)))
            ->fromUrl('https://api.test/openapi.json');

        Assert::same($index->get('getBlogTags')->operationId, 'getBlogTags');
    }

    public function sizelessMultiChunkSpecBodyIsAccumulated(): void
    {
        // chunked transfer: no advertised size, body larger than one read
        // chunk — the loader must accumulate the chunks, not keep the last
        $body = $this->specPaddedTo(20_000);
        $client = Understudy::for(ClientInterface::class);
        when(fn() => $client->sendRequest(Arg::any()))->returns(new Response(200, [], new StubStream(content: $body)));
        $loader = new SpecLoader(
            httpClient: $client,
            requestFactory: new Psr17Factory(),
        );

        Assert::same($loader->fromUrl('https://api.test/openapi.json')->get('getBlogTags')->operationId, 'getBlogTags');
    }

    public function consumedSeekableSpecBodyIsRewoundBeforeReading(): void
    {
        $body = json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR);
        $client = Understudy::for(ClientInterface::class);
        // the body arrives already consumed (a middleware logged it) — unlike
        // a (string) cast, read() does not rewind by itself
        when(fn() => $client->sendRequest(Arg::any()))->answers(
            static function () use ($body): Response {
                $response = new Response(200, [], $body);
                $response->getBody()->getContents(); // drain to EOF

                return $response;
            },
        );

        $loader = new SpecLoader(httpClient: $client, requestFactory: new Psr17Factory());

        Assert::same($loader->fromUrl('https://api.test/openapi.json')->get('getBlogTags')->operationId, 'getBlogTags');
    }

    /**
     * The fixture spec JSON padded (via an ignored top-level key) to exactly
     * $bytes bytes.
     */
    private function specPaddedTo(int $bytes): string
    {
        $spec = OpenApiFixture::spec();
        $spec['x-pad'] = '';
        $missing = $bytes - strlen(json_encode($spec, JSON_THROW_ON_ERROR));
        $spec['x-pad'] = str_repeat('a', $missing);
        $json = json_encode($spec, JSON_THROW_ON_ERROR);

        Assert::same(strlen($json), $bytes);

        return $json;
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

    private function loader(
        ClientInterface $client,
        array $headers = [],
        ?CacheInterface $cache = null,
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
