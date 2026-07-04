<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Server\Session\SessionInterface;
use Symfony\Component\Uid\Uuid;

final class FakeSession implements SessionInterface
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private array $attributes = [],
    ) {}

    #[\Override]
    public function getId(): Uuid
    {
        return Uuid::v4();
    }

    #[\Override]
    public function save(): bool
    {
        return true;
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, bool $overwrite = true): void
    {
        if ($overwrite || !array_key_exists($key, $this->attributes)) {
            $this->attributes[$key] = $value;
        }
    }

    #[\Override]
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    #[\Override]
    public function forget(string $key): void
    {
        unset($this->attributes[$key]);
    }

    #[\Override]
    public function clear(): void
    {
        $this->attributes = [];
    }

    #[\Override]
    public function pull(string $key, mixed $default = null): mixed
    {
        /** @var mixed $value */
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    #[\Override]
    public function all(): array
    {
        return $this->attributes;
    }

    #[\Override]
    public function hydrate(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    #[\Override]
    public function jsonSerialize(): mixed
    {
        return $this->attributes;
    }
}
