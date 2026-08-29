<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\StateMachine\StateMachine;
use Rasuvaeff\Yii3Mcp\Interceptor\SessionBudgetInterceptor;
use Rasuvaeff\Yii3Mcp\Tests\Support\SessionBudget\BudgetHarness;
use Rasuvaeff\Yii3Mcp\Tests\Support\SessionBudget\BudgetModel;
use Rasuvaeff\Yii3Mcp\Tests\Support\SessionBudget\CallToolCommand;
use Rasuvaeff\Yii3Mcp\Tests\Support\SessionBudget\ReInitializeCommand;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The budget is a lifecycle, not a function: what a call does depends on every
 * call before it in the session. The individual cases in
 * {@see SessionBudgetInterceptorTest} pin fixed sequences; this drives
 * generated ones — arbitrary interleavings of tool calls and re-initializes —
 * against a model of the documented behaviour.
 *
 * Sequential on purpose. The interceptor's counter is a plain
 * read-modify-write over the SDK's lock-free `SessionInterface` and is
 * explicitly NOT concurrency-safe (see the class docblock and the package
 * AGENTS.md); a concurrent model would fail by design instead of finding a bug.
 */
#[Test]
#[Covers(SessionBudgetInterceptor::class)]
final class SessionBudgetStatefulPropertyTest
{
    #[Property(runs: 200, timeoutMs: 2000)]
    public function callsAreAllowedExactlyWhileTheSessionBudgetLasts(CommandSequence $sequence): void
    {
        $initial = $sequence->initialModel;
        $budget = $initial instanceof BudgetModel ? $initial->budget : 1;

        $calls = 0;
        $resets = 0;
        $usedInSession = 0;
        $expectedExecuted = 0;

        foreach ($sequence->commands as $command) {
            if ($command instanceof ReInitializeCommand) {
                $resets++;
                $usedInSession = 0;

                continue;
            }

            $calls++;

            if ($usedInSession < $budget) {
                $usedInSession++;
                $expectedExecuted++;
            }
        }

        Classify::cover($calls > $budget, 'sequence outruns the budget', 25.0);
        Classify::cover($resets > 0, 'sequence re-initializes', 25.0);
        Classify::when($resets > 0 && $calls > $budget, 'exhausted, then reset');

        $harness = new BudgetHarness($budget);

        StateMachine::check($sequence, static fn(): BudgetHarness => $harness);

        // the commands check every step; this closes the sequence with the
        // aggregate the operator actually cares about — how many tool calls
        // the whole run let through
        Assert::same($harness->executed, $expectedExecuted);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function callsAreAllowedExactlyWhileTheSessionBudgetLastsGenerators(): array
    {
        return [
            'sequence' => Gen::flatMap(
                Gen::intBetween(1, 4),
                // a small budget on purpose: exhaustion has to be reachable
                // within a sequence short enough to shrink into something
                // readable
                static fn(int $budget): ArbitraryInterface => Gen::commands(
                    new BudgetModel($budget),
                    [
                        Gen::constant(new CallToolCommand()),
                        Gen::constant(new CallToolCommand()),
                        Gen::constant(new ReInitializeCommand()),
                    ],
                    minLength: 1,
                    maxLength: 12,
                ),
            ),
        ];
    }
}
