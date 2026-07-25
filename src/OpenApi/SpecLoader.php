<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;

/**
 * Fetches the OpenAPI document over HTTP — for APIs that serve their spec
 * from an endpoint (always current, no exported file to regenerate). The
 * same default headers as the operation calls apply, so a spec endpoint
 * behind authentication works out of the box.
 *
 * @api
 */
final readonly class SpecLoader
{
    /**
     * @param array<string, string> $headers e.g. ['Authorization' => 'Bearer …']
     */
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private array $headers = [],
        private ?CacheInterface $cache = null,
        private int $cacheTtl = 0,
    ) {
        if ($cacheTtl < 0) {
            throw new \InvalidArgumentException(sprintf('Cache TTL must not be negative, %d given', $cacheTtl));
        }
    }

    public function fromUrl(string $url): SpecIndex
    {
        $cacheKey = $this->cacheKey($url);
        $document = $this->readCache($cacheKey);

        if ($document !== null) {
            try {
                return SpecIndex::fromJson($document);
            } catch (InvalidSpecException) {
                // A corrupt cache entry is a cache failure; retry upstream.
            }
        }

        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('Accept', 'application/json');

        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            throw new InvalidSpecException(sprintf(
                'OpenAPI document at "%s" is not available: HTTP %d',
                $url,
                $response->getStatusCode(),
            ));
        }

        $document = (string) $response->getBody();
        $index = SpecIndex::fromJson($document);
        $this->writeCache($cacheKey, $document);

        return $index;
    }

    private function cacheKey(string $url): string
    {
        $headers = [];

        foreach ($this->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        ksort($headers);

        return 'yii3-mcp.openapi.' . hash('sha256', $url . "\0" . json_encode($headers, JSON_THROW_ON_ERROR));
    }

    private function readCache(string $key): ?string
    {
        if (!$this->cache instanceof CacheInterface || $this->cacheTtl === 0) {
            return null;
        }

        try {
            /** @var mixed $value */
            $value = $this->cache->get($key);
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private function writeCache(string $key, string $document): void
    {
        if (!$this->cache instanceof CacheInterface || $this->cacheTtl === 0) {
            return;
        }

        try {
            $this->cache->set($key, $document, $this->cacheTtl);
        } catch (\Throwable) {
            // Cache availability must not become OpenAPI availability.
        }
    }
}
