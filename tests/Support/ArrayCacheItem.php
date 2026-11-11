<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Psr\Cache\CacheItemInterface;

final class ArrayCacheItem implements CacheItemInterface
{
    private mixed $value = null;

    public function __construct(
        private readonly string $key,
        private readonly bool $hit = false,
    ) {}

    #[\Override]
    public function getKey(): string
    {
        return $this->key;
    }

    #[\Override]
    public function get(): mixed
    {
        return $this->value;
    }

    #[\Override]
    public function isHit(): bool
    {
        return $this->hit;
    }

    #[\Override]
    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    #[\Override]
    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        return $this;
    }

    #[\Override]
    public function expiresAfter(\DateInterval|int|null $time): static
    {
        return $this;
    }
}
