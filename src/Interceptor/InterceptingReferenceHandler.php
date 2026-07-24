<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ToolCallException;
use Mcp\Server\Session\SessionInterface;
use Rasuvaeff\Yii3Mcp\Identity\ClientIdentityContext;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;
use Rasuvaeff\Yii3Mcp\Visibility\PromptVisibilityInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ResourceVisibilityInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;

/**
 * Decorates the SDK reference handler with per-session visibility and
 * interceptor chains for every capability call: tools/call, prompts/get and
 * resources/read (static resources and templates alike). Visibility is
 * fail-closed — an invisible tool cannot be called even by its exact name,
 * and a hidden prompt/resource is reported as not found, indistinguishable
 * from a missing one.
 *
 * The client id resolved by {@see SharedSecretMiddleware} is read off the
 * request, exposed on every context and mirrored into the session
 * ({@see self::CLIENT_ID_SESSION_KEY}) so audit/telemetry bridges can
 * attribute calls without ever seeing the raw secret.
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
     * @param list<PromptGetInterceptorInterface> $promptInterceptors applied in order, first = outermost
     * @param list<ResourceReadInterceptorInterface> $resourceInterceptors applied in order, first = outermost
     */
    public function __construct(
        private ReferenceHandlerInterface $inner,
        private array $interceptors,
        private ?ToolVisibilityInterface $visibility = null,
        private array $promptInterceptors = [],
        private array $resourceInterceptors = [],
        private ?PromptVisibilityInterface $promptVisibility = null,
        private ?ResourceVisibilityInterface $resourceVisibility = null,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    #[\Override]
    public function handle(ElementReference $reference, array $arguments): mixed
    {
        if ($reference instanceof ToolReference) {
            return $this->handleTool($reference, $arguments);
        }

        if ($reference instanceof PromptReference) {
            return $this->handlePrompt($reference, $arguments);
        }

        if ($reference instanceof ResourceReference) {
            return $this->handleResource($reference, $arguments);
        }

        if ($reference instanceof ResourceTemplateReference) {
            return $this->handleResource($reference, $arguments);
        }

        return $this->inner->handle($reference, $arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function handleTool(ToolReference $reference, array $arguments): mixed
    {
        $session = $this->session($arguments);

        if ($this->visibility instanceof ToolVisibilityInterface && !$this->visibility->isVisible($reference->tool, $session)) {
            throw new ToolCallException(sprintf('Tool "%s" is not available in this session', $reference->tool->name));
        }

        $context = new ToolCallContext(
            toolName: $reference->tool->name,
            arguments: $this->cleaned($arguments),
            session: $session,
            clientId: $this->clientId($session),
        );

        $next = fn(): mixed => $this->inner->handle($reference, $arguments);

        foreach (array_reverse($this->interceptors) as $interceptor) {
            $current = $next;
            $next = static fn(): mixed => $interceptor->intercept($context, $current);
        }

        return $next();
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function handlePrompt(PromptReference $reference, array $arguments): mixed
    {
        $session = $this->session($arguments);

        if ($this->promptVisibility instanceof PromptVisibilityInterface && !$this->promptVisibility->isVisible($reference->prompt, $session)) {
            throw new PromptNotFoundException($reference->prompt->name);
        }

        $context = new PromptGetContext(
            promptName: $reference->prompt->name,
            arguments: $this->cleaned($arguments),
            session: $session,
            clientId: $this->clientId($session),
        );

        $next = fn(): mixed => $this->inner->handle($reference, $arguments);

        foreach (array_reverse($this->promptInterceptors) as $interceptor) {
            $current = $next;
            $next = static fn(): mixed => $interceptor->intercept($context, $current);
        }

        return $next();
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function handleResource(ResourceReference|ResourceTemplateReference $reference, array $arguments): mixed
    {
        $session = $this->session($arguments);
        /** @var mixed $rawUri */
        $rawUri = $arguments['uri'] ?? null;
        $uri = is_string($rawUri) ? $rawUri : '';

        if ($this->resourceVisibility instanceof ResourceVisibilityInterface) {
            $hidden = $reference instanceof ResourceTemplateReference
                ? !$this->resourceVisibility->isTemplateVisible($reference->resourceTemplate, $session)
                : !$this->resourceVisibility->isVisible($reference->resource, $session);

            if ($hidden) {
                throw new ResourceNotFoundException($uri);
            }
        }

        $variables = $this->cleaned($arguments);
        unset($variables['uri']);

        $context = new ResourceReadContext(
            uri: $uri,
            variables: $variables,
            uriTemplate: $reference instanceof ResourceTemplateReference ? $reference->resourceTemplate->uriTemplate : null,
            session: $session,
            clientId: $this->clientId($session),
        );

        $next = fn(): mixed => $this->inner->handle($reference, $arguments);

        foreach (array_reverse($this->resourceInterceptors) as $interceptor) {
            $current = $next;
            $next = static fn(): mixed => $interceptor->intercept($context, $current);
        }

        return $next();
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function session(array $arguments): ?SessionInterface
    {
        /** @var mixed $session */
        $session = $arguments['_session'] ?? null;

        return $session instanceof SessionInterface ? $session : null;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function cleaned(array $arguments): array
    {
        unset($arguments['_session'], $arguments['_request']);

        return $arguments;
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
