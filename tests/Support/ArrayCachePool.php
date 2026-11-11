<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Minimal in-memory PSR-6 pool: the SDK's OAuth discovery and JWKS key set
 * cache through it. Expiry is ignored — a test lives shorter than any TTL.
 */
final class ArrayCachePool implements CacheItemPoolInterface
{
    /** @var array<string, ArrayCacheItem> */
    private array $items = [];

    #[\Override]
    public function getItem(string $key): CacheItemInterface
    {
        return isset($this->items[$key]) ? clone $this->items[$key] : new ArrayCacheItem($key);
    }

    #[\Override]
    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    #[\Override]
    public function hasItem(string $key): bool
    {
        return isset($this->items[$key]);
    }

    #[\Override]
    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    #[\Override]
    public function deleteItem(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    #[\Override]
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->items[$key]);
        }

        return true;
    }

    #[\Override]
    public function save(CacheItemInterface $item): bool
    {
        $this->items[$item->getKey()] = (new ArrayCacheItem($item->getKey(), hit: true))->set($item->get());

        return true;
    }

    #[\Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    #[\Override]
    public function commit(): bool
    {
        return true;
    }
}
