<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support\SessionBudget;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * One `tools/call` against the session. The postcondition is the whole point
 * of the interceptor: a call is allowed exactly while the session's budget is
 * not yet used up, and a rejected call must not have reached the tool.
 */
final readonly class CallToolCommand implements Command
{
    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return $model instanceof BudgetModel;
    }

    #[\Override]
    public function nextState(mixed $model): mixed
    {
        if (!$model instanceof BudgetModel) {
            return $model;
        }

        // an exhausted budget throws before the counter is written, so the
        // model must not advance either — otherwise a rejected call would
        // silently push the counter further past the limit
        return $model->exhausted() ? $model : $model->consumed(executed: true);
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        if (!$system instanceof BudgetHarness) {
            return null;
        }

        return ['allowed' => $system->call(), 'executed' => $system->executed];
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        if (!$model instanceof BudgetModel || !is_array($result)) {
            return false;
        }

        $allowed = !$model->exhausted();

        return $result['allowed'] === $allowed
            && $result['executed'] === $model->executed + ($allowed ? 1 : 0);
    }

    #[\Override]
    public function __toString(): string
    {
        return 'callTool';
    }
}
