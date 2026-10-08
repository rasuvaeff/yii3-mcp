<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use InvalidArgumentException;
use Mcp\Schema\Enum\CacheScope;
use Mcp\Server\Wire\CachePolicy;
use Mcp\Server\Wire\Rev2026Codec;

/**
 * Turns the `cache_policy` params into the SDK's {@see CachePolicy}
 * (SEP-2549 caching hints on stateless-era answers), failing at config load
 * on anything it would otherwise ignore — a misspelled method name is a hint
 * silently never sent.
 *
 * @internal
 */
final readonly class CachePolicyParams
{
    /**
     * Methods whose answer depends on who is asking once a visibility filter
     * is configured: a `public` hint would let a shared cache serve one
     * caller's filtered view to another.
     */
    public const array CALLER_SPECIFIC_METHODS = [
        'tools/list',
        'prompts/list',
        'resources/list',
        'resources/templates/list',
        'resources/read',
    ];

    /**
     * @param array<array-key, mixed> $params
     *
     * @return CachePolicy|null null = the SDK default (ttl 0, private)
     */
    public static function parse(array $params): ?CachePolicy
    {
        $ttlMs = self::ttl($params['ttl_ms'] ?? 0, 'cache_policy.ttl_ms');
        $scope = self::scope($params['scope'] ?? 'private', 'cache_policy.scope');
        /** @var mixed $methods */
        $methods = $params['methods'] ?? [];

        if (!is_array($methods)) {
            throw new InvalidArgumentException('cache_policy.methods must be a map of method => [ttl_ms, scope]');
        }

        if ($ttlMs === 0 && $scope === CacheScope::Private && $methods === []) {
            return null;
        }

        $policy = CachePolicy::default($ttlMs, $scope);

        /** @var mixed $override */
        foreach ($methods as $method => $override) {
            if (!in_array($method, Rev2026Codec::CACHEABLE_METHODS, strict: true)) {
                throw new InvalidArgumentException(sprintf(
                    'cache_policy.methods: "%s" carries no caching hint; cacheable: %s',
                    (string) $method,
                    implode(', ', Rev2026Codec::CACHEABLE_METHODS),
                ));
            }

            if (!is_array($override)) {
                throw new InvalidArgumentException(sprintf('cache_policy.methods.%s must be ["ttl_ms" => int, "scope" => string]', $method));
            }

            $policy = $policy->withMethod(
                $method,
                self::ttl($override['ttl_ms'] ?? $ttlMs, sprintf('cache_policy.methods.%s.ttl_ms', $method)),
                self::scope($override['scope'] ?? $scope->value, sprintf('cache_policy.methods.%s.scope', $method)),
            );
        }

        return $policy;
    }

    /**
     * @return list<string> caller-specific methods the policy lets a shared cache keep
     */
    public static function publiclyCachedCallerSpecificMethods(CachePolicy $policy): array
    {
        $public = [];

        foreach (self::CALLER_SPECIFIC_METHODS as $method) {
            if ($policy->scopeFor($method) === CacheScope::Public && $policy->ttlFor($method) > 0) {
                $public[] = $method;
            }
        }

        return $public;
    }

    private static function ttl(mixed $value, string $param): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException(sprintf('%s must be an integer of zero or more milliseconds', $param));
        }

        return $value;
    }

    private static function scope(mixed $value, string $param): CacheScope
    {
        $scope = is_string($value) ? CacheScope::tryFrom($value) : null;

        return $scope ?? throw new InvalidArgumentException(sprintf('%s must be "private" or "public"', $param));
    }
}
