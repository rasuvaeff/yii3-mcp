<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support\StatelessBudget;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * Time passes — possibly across a window boundary, which is the only thing
 * that resets a stateless budget.
 */
final readonly class AdvanceClockCommand implements Command
{
    public function __construct(
        private int $seconds,
    ) {}

    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return $model instanceof StatelessBudgetModel;
    }

    #[\Override]
    public function nextState(mixed $model): mixed
    {
        return $model instanceof StatelessBudgetModel ? $model->advanced($this->seconds) : $model;
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        if ($system instanceof StatelessBudgetHarness) {
            $system->now += $this->seconds;
        }

        return null;
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        return $result === null;
    }

    #[\Override]
    public function __toString(): string
    {
        return 'advance(' . $this->seconds . 's)';
    }
}
