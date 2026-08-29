<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support\SessionBudget;

/**
 * The model {@see \Rasuvaeff\Yii3Mcp\Interceptor\SessionBudgetInterceptor} is
 * checked against: a session's consumed calls, plus how many downstream calls
 * actually ran. Immutable — the state machine threads it through the sequence
 * and replays it during shrinking.
 */
final readonly class BudgetModel
{
    public function __construct(
        public int $budget,
        public int $used = 0,
        public int $executed = 0,
    ) {}

    public function exhausted(): bool
    {
        return $this->used >= $this->budget;
    }

    public function consumed(bool $executed): self
    {
        return new self($this->budget, $this->used + 1, $this->executed + ($executed ? 1 : 0));
    }

    public function reset(): self
    {
        return new self($this->budget, 0, $this->executed);
    }
}
