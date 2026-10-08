<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support\StatelessBudget;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Server\Stateless\RequestMeta;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallBudgetInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeCache;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeSession;

/**
 * One interceptor over one cache and a controllable clock; every call gets
 * a fresh session carrying RequestMeta, exactly what the SDK hands a
 * stateless request — so nothing can survive in the session between calls.
 */
final class StatelessBudgetHarness
{
    public int $executed = 0;

    public int $now = 0;

    private readonly ToolCallBudgetInterceptor $interceptor;

    public function __construct(int $budget, int $window)
    {
        $this->interceptor = new ToolCallBudgetInterceptor(
            budget: $budget,
            cache: new FakeCache(),
            window: $window,
            clock: fn(): int => $this->now,
        );
    }

    /**
     * @return bool whether the call was allowed through
     */
    public function call(?string $client): bool
    {
        $context = new ToolCallContext(
            toolName: 'greet',
            arguments: [],
            session: new FakeSession([RequestMeta::class => new RequestMeta('2026-07-28', new ClientCapabilities())]),
            clientId: $client,
        );

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
}
