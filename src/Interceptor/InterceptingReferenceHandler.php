<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Server\Session\SessionInterface;

/**
 * Decorates the SDK reference handler with the tool-call interceptor chain.
 * Only tools/call goes through the chain; prompts, resources and resource
 * templates are delegated untouched.
 *
 * @api
 */
final readonly class InterceptingReferenceHandler implements ReferenceHandlerInterface
{
    /**
     * @param list<ToolCallInterceptorInterface> $interceptors applied in order, first = outermost
     */
    public function __construct(
        private ReferenceHandlerInterface $inner,
        private array $interceptors,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    #[\Override]
    public function handle(ElementReference $reference, array $arguments): mixed
    {
        if (!$reference instanceof ToolReference || $this->interceptors === []) {
            return $this->inner->handle($reference, $arguments);
        }

        /** @var mixed $session */
        $session = $arguments['_session'] ?? null;

        $cleaned = $arguments;
        unset($cleaned['_session'], $cleaned['_request']);

        $context = new ToolCallContext(
            toolName: $reference->tool->name,
            arguments: $cleaned,
            session: $session instanceof SessionInterface ? $session : null,
        );

        $next = fn(): mixed => $this->inner->handle($reference, $arguments);

        foreach (array_reverse($this->interceptors) as $interceptor) {
            $current = $next;
            $next = static fn(): mixed => $interceptor->intercept($context, $current);
        }

        return $next();
    }
}
