<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use Mcp\Server\Session\SessionInterface;
use Rasuvaeff\Yii3Mcp\Identity\ClientIdentityContext;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;

/**
 * Decorates the SDK reference handler with per-session tool visibility
 * (fail-closed: an invisible tool cannot be called even by its exact name)
 * and the tool-call interceptor chain. Only tools/call is affected; prompts,
 * resources and resource templates are delegated untouched.
 *
 * The client id resolved by {@see SharedSecretMiddleware} is read off the
 * request, exposed on the {@see ToolCallContext} and mirrored into the
 * session ({@see self::CLIENT_ID_SESSION_KEY}) so audit/telemetry bridges
 * can attribute calls without ever seeing the raw secret.
 *
 * @api
 */
final readonly class InterceptingReferenceHandler implements ReferenceHandlerInterface
{
    /**
     * Session key mirroring the resolved client id.
     */
    public const string CLIENT_ID_SESSION_KEY = 'rasuvaeff.yii3-mcp.client-id';

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

        $clientId = $this->clientId($session);

        $cleaned = $arguments;
        unset($cleaned['_session'], $cleaned['_request']);

        $context = new ToolCallContext(
            toolName: $reference->tool->name,
            arguments: $cleaned,
            session: $session,
            clientId: $clientId,
        );

        $next = fn(): mixed => $this->inner->handle($reference, $arguments);

        foreach (array_reverse($this->interceptors) as $interceptor) {
            $current = $next;
            $next = static fn(): mixed => $interceptor->intercept($context, $current);
        }

        return $next();
    }

    /**
     * The client id {@see SharedSecretMiddleware} resolved for the current
     * request (carried by {@see ClientIdentityContext} — the SDK hands this
     * handler the JSON-RPC request, not the PSR-7 one). A present id is
     * mirrored into the session; absent one (stdio, no middleware) falls
     * back to the session's mirror so a long-lived session keeps its
     * attribution.
     */
    private function clientId(?SessionInterface $session): ?string
    {
        $clientId = ClientIdentityContext::current();

        if ($session instanceof SessionInterface) {
            if ($clientId !== null) {
                $session->set(self::CLIENT_ID_SESSION_KEY, $clientId);
            } else {
                /** @var mixed $stored */
                $stored = $session->get(self::CLIENT_ID_SESSION_KEY);
                $clientId = is_string($stored) ? $stored : null;
            }
        }

        return $clientId;
    }
}
