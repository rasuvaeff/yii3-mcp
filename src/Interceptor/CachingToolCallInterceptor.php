<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentityProviderInterface;
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
 * is never acceptable regardless of configuration. When an
 * $identityProvider is configured, the resolved ExecutionIdentity is part
 * of the key too: delegated upstream credentials mean the same tool + same
 * arguments can produce identity-specific results, and the identity may be
 * finer-grained than the client id (many end users behind one MCP client).
 * An identity provider failure fails CLOSED for cached tools — serving a
 * result without knowing whose it is would be the exact leak the key
 * exists to prevent.
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
     * PSR-16 only guarantees support for keys up to 64 characters; the
     * sha256 hex digest is truncated so prefix + digest fits exactly.
     * 45 hex chars = 180 bits — far beyond accidental-collision range.
     */
    private const int KEY_HASH_LENGTH = 45;

    /**
     * @param array<string, int> $ttlSeconds tool name => TTL in seconds; tools absent from this map are never cached
     */
    public function __construct(
        private CacheInterface $cache,
        private array $ttlSeconds,
        private ?ExecutionIdentityProviderInterface $identityProvider = null,
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
        $identity = '';

        if ($this->identityProvider instanceof ExecutionIdentityProviderInterface) {
            $current = $this->identityProvider->current();
            $identity = json_encode([$current->subjectId, $current->tenantId, $current->clientId], JSON_THROW_ON_ERROR);
        }

        return self::KEY_PREFIX . substr(
            hash('sha256', $clientId . '|' . $context->toolName . '|' . $identity . '|' . $canonicalArguments),
            0,
            self::KEY_HASH_LENGTH,
        );
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
