<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

/**
 * Port to the application's rate limiter for tool calls. This package
 * deliberately ships NO limiter storage of its own — implement this over
 * `yiisoft/rate-limiter`, Redis, or whatever the application already runs,
 * and wire {@see RateLimitInterceptor} into the `interceptors` params list.
 *
 * @api
 */
interface ToolCallLimiterInterface
{
    /**
     * Whether this client may execute this tool call now. Returning false
     * rejects the call; a thrown exception also rejects it (enforced quotas
     * fail closed when the limiter backend is unavailable).
     *
     * A `null` client id means the transport carries no authenticated
     * identity (stdio, no middleware) — it is typed as absence, never as a
     * reserved string, so it can never collide with a real client id. The
     * implementation decides how to bucket anonymous calls.
     */
    public function allow(?string $clientId, string $toolName): bool;
}
