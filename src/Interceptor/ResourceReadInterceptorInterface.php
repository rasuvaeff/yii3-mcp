<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

/**
 * Public extension point wrapping every resources/read — static resources
 * and resource templates alike, whatever the registration path.
 * Implementations run in configured order (first = outermost) and decide
 * whether to call $next: auditing, tracing, ACL.
 *
 * Throw {@see \Mcp\Exception\ResourceReadException} to reject the call with
 * a client-visible message; any other exception becomes an opaque internal
 * error. To pretend the resource does not exist, throw
 * {@see \Mcp\Exception\ResourceNotFoundException}.
 *
 * @api
 */
interface ResourceReadInterceptorInterface
{
    /**
     * @param callable(): mixed $next the rest of the chain, ending in the actual resource handler
     *
     * @return mixed the raw resource result (before SDK result formatting)
     */
    public function intercept(ResourceReadContext $context, callable $next): mixed;
}
