<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use InvalidArgumentException;
use Mcp\Exception\ToolCallException;
use Mcp\Server\Session\SessionInterface;

/**
 * Caps the number of tools/call per MCP session (initialize → TTL expiry).
 * Protection against an agent burning calls in a loop INSIDE one session —
 * not a client quota: re-initializing starts a fresh counter; client quotas
 * belong to an application-level rate limiter.
 *
 * An exhausted budget is reported as a regular MCP tool-error envelope, so
 * the agent sees the reason instead of a transport failure.
 *
 * @api
 */
final readonly class SessionBudgetInterceptor implements ToolCallInterceptorInterface
{
    private const string COUNTER_KEY = 'rasuvaeff.yii3-mcp.tool-calls';

    public function __construct(
        private int $budget,
    ) {
        if ($budget < 1) {
            throw new InvalidArgumentException(sprintf('Session tool-call budget must be at least 1, %d given', $budget));
        }
    }

    #[\Override]
    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        $session = $context->session;

        if (!$session instanceof SessionInterface) {
            return $next();
        }

        /** @var mixed $used */
        $used = $session->get(self::COUNTER_KEY, 0);
        $used = is_int($used) ? $used : 0;

        if ($used >= $this->budget) {
            throw new ToolCallException(sprintf('Session tool-call budget of %d is exhausted; start a new session or raise the budget', $this->budget));
        }

        $session->set(self::COUNTER_KEY, $used + 1);

        return $next();
    }
}
