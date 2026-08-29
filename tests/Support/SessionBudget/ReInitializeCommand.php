<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support\SessionBudget;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * A client re-running `initialize`. The documented escape hatch from an
 * exhausted budget — and the reason this guard is explicitly NOT a client
 * quota: anyone may reset it by starting a new session.
 */
final readonly class ReInitializeCommand implements Command
{
    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return $model instanceof BudgetModel;
    }

    #[\Override]
    public function nextState(mixed $model): mixed
    {
        return $model instanceof BudgetModel ? $model->reset() : $model;
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        if ($system instanceof BudgetHarness) {
            $system->newSession();
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
        return 'reInitialize';
    }
}
