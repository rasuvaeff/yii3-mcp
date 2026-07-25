<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Caches successful tool results per client, for tools explicitly opted
 * into `$ttlSeconds` — a read-heavy tool (a lookup table, an OpenAPI GET)
 * called repeatedly with the same arguments inside a session skips the
 * handler entirely. Opt-in by tool name only; the interceptor has no notion
 * of which tools are safe to cache.
 *
 * The cache key always includes the resolved client id (falling back to
 * `anonymous` on transports without one, e.g. stdio) — a shared cache
 * between distinct clients would leak one client's result to another, which
 * is never acceptable regardless of configuration.
 *
 * Exceptions are never cached (only `$next()`'s successful return value
 * is written). A cache read/write failure fails OPEN — this is an
 * availability optimization, not a security gate, so an unavailable cache
 * simply means the tool runs.
 *
 * @api
 */
final readonly class CachingToolCallInterceptor implements ToolCallInterceptorInterface
{
    private const string KEY_PREFIX = 'yii3-mcp.toolcache.';

    /**
     * @param array<string, int> $ttlSeconds tool name => TTL in seconds; tools absent from this map are never cached
     */
    public function __construct(
        private CacheInterface $cache,
        private array $ttlSeconds,
    ) {}

    #[\Override]
    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        $ttl = $this->ttlSeconds[$context->toolName] ?? null;

        if ($ttl === null) {
            return $next();
        }

        $key = $this->cacheKey($context);

        try {
            /** @var mixed $cached */
            $cached = $this->cache->get($key);
        } catch (Throwable) {
            return $next();
        }

        if (is_array($cached) && array_key_exists('v', $cached)) {
            return $cached['v'];
        }

        /** @var mixed $result */
        $result = $next();

        try {
            $this->cache->set($key, ['v' => $result], $ttl);
        } catch (Throwable) {
            // a failed cache write must not fail an otherwise successful call
        }

        return $result;
    }

    private function cacheKey(ToolCallContext $context): string
    {
        $clientId = $context->clientId ?? 'anonymous';
        $canonicalArguments = json_encode($this->canonicalized($context->arguments), JSON_THROW_ON_ERROR);

        return self::KEY_PREFIX . hash('sha256', $clientId . '|' . $context->toolName . '|' . $canonicalArguments);
    }

    /**
     * Sorts keys recursively so argument order never affects the cache key.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function canonicalized(array $value): array
    {
        /** @var mixed $item */
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalized($item);
            }
        }

        ksort($value);

        return $value;
    }
}
