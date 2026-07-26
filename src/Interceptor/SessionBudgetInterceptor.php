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
 * NOT safe against concurrent calls on the SAME session: the counter is a
 * plain read-modify-write (`get()` then `set()`) with no compare-and-swap,
 * because {@see SessionInterface} (from `mcp/sdk`) exposes none and is a
 * generic key-value abstraction over an arbitrary {@see
 * \Mcp\Server\Session\SessionStoreInterface} backend — there is no lock
 * primitive to reach for that would work across every backend a consumer
 * might bind (the shipped {@see \Mcp\Server\Session\FileSessionStore}
 * locks only its own write, not the read-modify-write span). N concurrent
 * requests racing on one session can overrun the budget by up to N-1 calls.
 * This is accepted: the guard's purpose is stopping a runaway agent loop,
 * not enforcing a hard cap under adversarial concurrency — a real quota
 * belongs in an application-level rate limiter with a proper atomic store.
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
