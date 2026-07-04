<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

/**
 * Public extension point wrapping every tools/call — attribute tools,
 * OpenAPI-bridged operations and configurator-registered handlers alike.
 * Implementations run in configured order (first = outermost) and decide
 * whether to call $next: tracing, rate limiting, ACL, budgets.
 *
 * Throw {@see \Mcp\Exception\ToolCallException} to reject the call with a
 * regular MCP tool-error envelope; any other exception becomes an opaque
 * internal error.
 *
 * @api
 */
interface ToolCallInterceptorInterface
{
    /**
     * @param callable(): mixed $next the rest of the chain, ending in the actual tool handler
     *
     * @return mixed the raw tool result (before SDK result formatting)
     */
    public function intercept(ToolCallContext $context, callable $next): mixed;
}
