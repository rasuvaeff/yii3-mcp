<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

final class FakeCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    public DateInterval|int|null $lastTtl = null;

    public function __construct(
        private readonly bool $throwOnRead = false,
        private readonly bool $throwOnWrite = false,
    ) {}

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->throwOnRead) {
            throw new \RuntimeException('cache read failed');
        }

        return $this->values[$key] ?? $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        if ($this->throwOnWrite) {
            throw new \RuntimeException('cache write failed');
        }

        $this->values[$key] = $value;
        $this->lastTtl = $ttl;

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    #[\Override]
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
}
