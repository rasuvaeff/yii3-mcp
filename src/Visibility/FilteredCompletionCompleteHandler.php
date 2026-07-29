<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Visibility;

use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\PromptReference;
use Mcp\Schema\Request\CompletionCompleteRequest;
use Mcp\Schema\ResourceReference;
use Mcp\Schema\Result\CompletionCompleteResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * completion/complete with per-session visibility — registered by
 * McpServerFactory ahead of the SDK handler (custom request handlers win),
 * which it then delegates to for everything visible.
 *
 * The SDK's own handler reads the registry DIRECTLY instead of going through
 * the reference handler, so neither visibility nor the interceptor chains
 * apply to it: a prompt hidden from a session still returned completions for
 * its arguments, leaking both the argument values and the prompt's existence
 * (a hidden prompt answered, a missing one errored). This handler closes that
 * hole with the same fail-closed shape the rest of the package uses — a
 * hidden prompt/resource is reported as not found, byte-for-byte identical to
 * a missing one, because the message comes from the SDK's own exception.
 *
 * @implements RequestHandlerInterface<CompletionCompleteResult>
 *
 * @internal wired by {@see \Rasuvaeff\Yii3Mcp\McpServerFactory}
 */
final readonly class FilteredCompletionCompleteHandler implements RequestHandlerInterface
{
    /**
     * @param RequestHandlerInterface<CompletionCompleteResult> $inner the SDK handler serving the visible refs
     */
    public function __construct(
        private RegistryInterface $registry,
        private RequestHandlerInterface $inner,
        private ?PromptVisibilityInterface $promptVisibility = null,
        private ?ResourceVisibilityInterface $resourceVisibility = null,
    ) {}

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request instanceof CompletionCompleteRequest;
    }

    /**
     * @return Response<CompletionCompleteResult>|Error
     */
    #[\Override]
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CompletionCompleteRequest);

        $notFound = $this->notFoundMessage($request->ref, $session);

        return $notFound === null
            ? $this->inner->handle($request, $session)
            : Error::forResourceNotFound($notFound, $request->getId());
    }

    /**
     * A ref that does not resolve at all is left to the inner handler, which
     * produces the canonical error — this must never become a second place
     * where "unknown capability" is phrased.
     */
    private function notFoundMessage(PromptReference|ResourceReference $ref, SessionInterface $session): ?string
    {
        if ($ref instanceof PromptReference) {
            if (!$this->promptVisibility instanceof PromptVisibilityInterface) {
                return null;
            }

            try {
                $prompt = $this->registry->getPrompt($ref->name)->prompt;
            } catch (PromptNotFoundException) {
                return null;
            }

            return $this->promptVisibility->isVisible($prompt, $session)
                ? null
                : (new PromptNotFoundException($ref->name))->getMessage();
        }

        if (!$this->resourceVisibility instanceof ResourceVisibilityInterface) {
            return null;
        }

        try {
            $reference = $this->registry->getResource($ref->uri);
        } catch (ResourceNotFoundException) {
            return null;
        }

        $visible = $reference instanceof ResourceTemplateReference
            ? $this->resourceVisibility->isTemplateVisible($reference->resourceTemplate, $session)
            : $this->resourceVisibility->isVisible($reference->resource, $session);

        return $visible
            ? null
            : (new ResourceNotFoundException($ref->uri))->getMessage();
    }
}
