<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support\StatelessBudget;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * One stateless `tools/call` from a client (null = no client id). Allowed
 * exactly while that client's budget for the current window lasts.
 */
final readonly class StatelessCallCommand implements Command
{
    public function __construct(
        private ?string $client,
    ) {}

    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return $model instanceof StatelessBudgetModel;
    }

    #[\Override]
    public function nextState(mixed $model): mixed
    {
        if (!$model instanceof StatelessBudgetModel) {
            return $model;
        }

        return $model->exhausted($this->client) ? $model : $model->consumed($this->client);
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        if (!$system instanceof StatelessBudgetHarness) {
            return null;
        }

        return ['allowed' => $system->call($this->client), 'executed' => $system->executed];
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        if (!$model instanceof StatelessBudgetModel || !is_array($result)) {
            return false;
        }

        $allowed = !$model->exhausted($this->client);

        return $result['allowed'] === $allowed
            && $result['executed'] === $model->executed + ($allowed ? 1 : 0);
    }

    #[\Override]
    public function __toString(): string
    {
        return 'call(' . ($this->client ?? 'anonymous') . ')';
    }
}
