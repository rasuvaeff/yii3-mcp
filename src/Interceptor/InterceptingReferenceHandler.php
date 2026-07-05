<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use Mcp\Server\Session\SessionInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;

/**
 * Decorates the SDK reference handler with per-session tool visibility
 * (fail-closed: an invisible tool cannot be called even by its exact name)
 * and the tool-call interceptor chain. Only tools/call is affected; prompts,
 * resources and resource templates are delegated untouched.
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
        private ?ToolVisibilityInterface $visibility = null,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    #[\Override]
    public function handle(ElementReference $reference, array $arguments): mixed
    {
        if (!$reference instanceof ToolReference) {
            return $this->inner->handle($reference, $arguments);
        }

        /** @var mixed $session */
        $session = $arguments['_session'] ?? null;
        $session = $session instanceof SessionInterface ? $session : null;

        if ($this->visibility instanceof ToolVisibilityInterface && !$this->visibility->isVisible($reference->tool, $session)) {
            throw new ToolCallException(sprintf('Tool "%s" is not available in this session', $reference->tool->name));
        }

        $cleaned = $arguments;
        unset($cleaned['_session'], $cleaned['_request']);

        $context = new ToolCallContext(
            toolName: $reference->tool->name,
            arguments: $cleaned,
            session: $session,
        );

        $next = fn(): mixed => $this->inner->handle($reference, $arguments);

        foreach (array_reverse($this->interceptors) as $interceptor) {
            $current = $next;
            $next = static fn(): mixed => $interceptor->intercept($context, $current);
        }

        return $next();
    }
}
