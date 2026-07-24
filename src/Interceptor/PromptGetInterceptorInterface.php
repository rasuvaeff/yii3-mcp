<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

/**
 * Public extension point wrapping every prompts/get — attribute prompts and
 * configurator-registered ones (e.g. MarkdownPromptsConfigurator) alike.
 * Implementations run in configured order (first = outermost) and decide
 * whether to call $next: auditing, tracing, ACL.
 *
 * Throw {@see \Mcp\Exception\PromptGetException} to reject the call with a
 * client-visible message; any other exception becomes an opaque internal
 * error. To pretend the prompt does not exist, throw
 * {@see \Mcp\Exception\PromptNotFoundException}.
 *
 * @api
 */
interface PromptGetInterceptorInterface
{
    /**
     * @param callable(): mixed $next the rest of the chain, ending in the actual prompt handler
     *
     * @return mixed the raw prompt result (before SDK result formatting)
     */
    public function intercept(PromptGetContext $context, callable $next): mixed;
}
