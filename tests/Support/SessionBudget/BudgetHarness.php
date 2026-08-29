<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support\SessionBudget;

use Mcp\Exception\ToolCallException;
use Rasuvaeff\Yii3Mcp\Interceptor\SessionBudgetInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeSession;

/**
 * The system under test for the stateful budget property: one interceptor
 * over one session, plus the counter of downstream calls that actually ran —
 * "the budget was consumed" and "the tool executed" are different facts and a
 * model-based test has to be able to tell them apart.
 *
 * Sequential by construction. The interceptor's counter is deliberately not
 * safe against concurrent calls on one session (a plain read-modify-write
 * over the SDK's lock-free `SessionInterface`), so a concurrent model would
 * fail by design rather than find a bug.
 */
final class BudgetHarness
{
    public int $executed = 0;

    private readonly SessionBudgetInterceptor $interceptor;

    private FakeSession $session;

    public function __construct(
        public readonly int $budget,
    ) {
        $this->interceptor = new SessionBudgetInterceptor($budget);
        $this->session = new FakeSession();
    }

    /**
     * @return bool whether the call was allowed through
     */
    public function call(): bool
    {
        $context = new ToolCallContext(toolName: 'greet', arguments: [], session: $this->session);

        try {
            $this->interceptor->intercept($context, function (): string {
                $this->executed++;

                return 'ok';
            });
        } catch (ToolCallException) {
            return false;
        }

        return true;
    }

    /**
     * A fresh session — what a client re-running `initialize` gets. The
     * interceptor is reused on purpose: the budget lives in the session, not
     * in the interceptor.
     */
    public function newSession(): void
    {
        $this->session = new FakeSession();
    }
}
