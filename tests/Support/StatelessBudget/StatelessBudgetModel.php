<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support\StatelessBudget;

/**
 * The model the stateless-era budget is checked against: calls consumed per
 * (client, window), the clock, and how many downstream calls ran. A null
 * client is its own bucket — every anonymous caller shares it.
 */
final readonly class StatelessBudgetModel
{
    /**
     * @param array<string, int> $used "client|bucket" => consumed calls
     */
    public function __construct(
        public int $budget,
        public int $window,
        public int $now = 0,
        public array $used = [],
        public int $executed = 0,
    ) {}

    public function exhausted(?string $client): bool
    {
        return ($this->used[$this->key($client)] ?? 0) >= $this->budget;
    }

    public function consumed(?string $client): self
    {
        $used = $this->used;
        $used[$this->key($client)] = ($used[$this->key($client)] ?? 0) + 1;

        return new self($this->budget, $this->window, $this->now, $used, $this->executed + 1);
    }

    public function advanced(int $seconds): self
    {
        return new self($this->budget, $this->window, $this->now + $seconds, $this->used, $this->executed);
    }

    private function key(?string $client): string
    {
        return ($client === null ? "\0anonymous" : 'client:' . $client) . '|' . intdiv($this->now, $this->window);
    }
}
