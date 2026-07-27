<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Mcp\Exception\ToolCallException;

/**
 * Delegates per-client/per-tool limits to the application's rate limiter via
 * {@see ToolCallLimiterInterface}. Complements (does not replace)
 * {@see SessionBudgetInterceptor}: the budget is an in-session anti-loop
 * guard, this is the client quota keyed by the identity that
 * {@see \Rasuvaeff\Yii3Mcp\Identity\SecretResolverInterface} resolved.
 *
 * Fail-closed: when the limiter backend throws, the call is rejected — an
 * enforced quota must not silently turn into "unlimited" on an outage.
 *
 * An absent client id (stdio, no middleware) is passed to the limiter as
 * `null` — typed absence, never a reserved string like "anonymous" that a
 * real client id could collide with.
 *
 * @api
 */
final readonly class RateLimitInterceptor implements ToolCallInterceptorInterface
{
    public function __construct(
        private ToolCallLimiterInterface $limiter,
    ) {}

    #[\Override]
    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        $clientId = $context->clientId;

        try {
            $allowed = $this->limiter->allow($clientId, $context->toolName);
        } catch (\Throwable $failure) {
            throw new ToolCallException(sprintf(
                'Rate limiter is unavailable (%s); the enforced quota fails closed',
                $failure::class,
            ), 0, $failure);
        }

        if (!$allowed) {
            throw new ToolCallException(sprintf(
                'Rate limit exceeded for client %s on tool "%s"',
                $clientId === null ? '(anonymous)' : '"' . $clientId . '"',
                $context->toolName,
            ));
        }

        return $next();
    }
}
