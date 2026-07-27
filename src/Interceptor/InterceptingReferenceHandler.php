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
use Rasuvaeff\Yii3Mcp\Exception\SessionOwnershipException;
use Rasuvaeff\Yii3Mcp\Identity\ClientIdentityContext;
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
 * The client identity of a call comes FROM THE SESSION first
 * ({@see self::CLIENT_ID_SESSION_KEY} — the immutable owner
 * {@see \Rasuvaeff\Yii3Mcp\McpAction} stamps at `initialize`), so it travels
 * with the request instead of through process state and stays correct even in
 * a concurrent (Fiber-interleaving) runtime. The process-local
 * {@see ClientIdentityContext} is only the fallback for sessions that carry
 * no owner yet (a pre-ownership session, an embedding without McpAction) —
 * in that case the id is mirrored into the session on first use. When both
 * are present and DISAGREE, the call fails closed with
 * {@see SessionOwnershipException}: a session must never be re-attributed to
 * another client. This check runs before visibility, so a foreign session is
 * never consulted for what the caller may see.
 *
 * The stored id is attribution, not a live revocation check: if a client's
 * secret is removed from `client_secrets` mid-session, its already-mirrored
 * id survives in the session until the session's own TTL expires. Revoking
 * access immediately requires also invalidating the affected sessions.
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
        // identity first, visibility second: a foreign session must be
        // rejected before it is consulted for what the caller may see
        $clientId = $this->clientId($session);

        if ($this->visibility instanceof ToolVisibilityInterface && !$this->visibility->isVisible($reference->tool, $session)) {
            throw new ToolCallException(sprintf('Tool "%s" is not available in this session', $reference->tool->name));
        }

        $context = new ToolCallContext(
            toolName: $reference->tool->name,
            arguments: $this->cleaned($arguments),
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
     * @param array<string, mixed> $arguments
     */
    private function handlePrompt(PromptReference $reference, array $arguments): mixed
    {
        $session = $this->session($arguments);
        $clientId = $this->clientId($session);

        if ($this->promptVisibility instanceof PromptVisibilityInterface && !$this->promptVisibility->isVisible($reference->prompt, $session)) {
            throw new PromptNotFoundException($reference->prompt->name);
        }

        $context = new PromptGetContext(
            promptName: $reference->prompt->name,
            arguments: $this->cleaned($arguments),
            session: $session,
            clientId: $clientId,
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
        $clientId = $this->clientId($session);
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
            clientId: $clientId,
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
     * The session's immutable owner wins; the process-local
     * {@see ClientIdentityContext} (armed per request by McpAction — the SDK
     * hands this handler the JSON-RPC request, not the PSR-7 one) covers
     * sessions with no recorded owner and sessionless calls, and its id is
     * mirrored into the session on first use. An owner that DISAGREES with
     * the current request's id fails closed: a session is never re-attributed.
     */
    private function clientId(?SessionInterface $session): ?string
    {
        $clientId = ClientIdentityContext::current();

        if (!$session instanceof SessionInterface) {
            return $clientId;
        }

        /** @var mixed $stored */
        $stored = $session->get(self::CLIENT_ID_SESSION_KEY);

        if (is_string($stored)) {
            if ($clientId !== null && $clientId !== $stored) {
                throw new SessionOwnershipException(sprintf(
                    'Session belongs to another client; refusing to serve client "%s"',
                    $clientId,
                ));
            }

            return $stored;
        }

        if ($clientId !== null) {
            $session->set(self::CLIENT_ID_SESSION_KEY, $clientId);
        }

        return $clientId;
    }
}
