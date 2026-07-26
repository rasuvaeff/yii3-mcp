<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;

/**
 * Fetches the OpenAPI document over HTTP — for APIs that serve their spec
 * from an endpoint (always current, no exported file to regenerate).
 *
 * `$headers` is the SPEC fetch's own credential scope (`spec_headers` in
 * params), deliberately separate from the operation calls' headers: when
 * `spec_path` and `base_url` point at different origins, a shared header set
 * would send the API's token to the spec host. A spec URL embedding
 * credentials (userinfo) is rejected outright — such a URL ends up in
 * diagnostics, logs and exception messages.
 *
 * @api
 */
final readonly class SpecLoader
{
    private const string KEY_PREFIX = 'yii3-mcp.openapi.';

    /**
     * PSR-16 only guarantees support for keys up to 64 characters; the
     * sha256 hex digest is truncated so prefix + digest fits exactly.
     * 47 hex chars = 188 bits — far beyond accidental-collision range.
     */
    private const int KEY_HASH_LENGTH = 47;

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
        $parts = parse_url($url);

        // parse_url sets "user" (possibly empty) whenever a userinfo section
        // exists; the URL travels into diagnostics and exception messages,
        // so it must never be a credential carrier
        if (is_array($parts) && isset($parts['user'])) {
            throw new InvalidSpecException('OpenAPI spec URL must not embed credentials; pass them via spec_headers');
        }

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

        $document = $this->readBounded($response->getBody(), $url);
        $index = SpecIndex::fromJson($document);
        $this->writeCache($cacheKey, $document);

        return $index;
    }

    /**
     * Incremental bounded read up to {@see SpecIndex::MAX_DOCUMENT_BYTES}:
     * the spec endpoint is upstream input, so an oversized (or unbounded
     * chunked) body fails before it is buffered, not after.
     */
    private function readBounded(\Psr\Http\Message\StreamInterface $stream, string $url): string
    {
        // unlike a (string) cast, read() starts at the CURRENT position — a
        // seekable body that was already consumed (or created at EOF) must be
        // rewound or it reads as empty
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $cap = SpecIndex::MAX_DOCUMENT_BYTES;
        $size = $stream->getSize();

        if ($size !== null && $size > $cap) {
            throw new InvalidSpecException(sprintf('OpenAPI document at "%s" of %d bytes exceeds the %d-byte limit', $url, $size, $cap));
        }

        $contents = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;

            if (strlen($contents) > $cap) {
                throw new InvalidSpecException(sprintf('OpenAPI document at "%s" exceeds the %d-byte limit', $url, $cap));
            }
        }

        return $contents;
    }

    private function cacheKey(string $url): string
    {
        $headers = [];

        foreach ($this->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        ksort($headers);

        return self::KEY_PREFIX . substr(
            hash('sha256', $url . "\0" . json_encode($headers, JSON_THROW_ON_ERROR)),
            0,
            self::KEY_HASH_LENGTH,
        );
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
