<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecLoader;
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

    /**
     * @param array<string, string> $headers
     */
    private function loader(FakeHttpClient $client, array $headers = []): SpecLoader
    {
        return new SpecLoader(
            httpClient: $client,
            requestFactory: new Psr17Factory(),
            headers: $headers,
        );
    }
}
