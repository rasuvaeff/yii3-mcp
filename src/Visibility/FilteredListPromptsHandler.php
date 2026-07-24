<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Visibility;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Prompt;
use Mcp\Schema\Request\ListPromptsRequest;
use Mcp\Schema\Result\ListPromptsResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * prompts/list with per-session visibility filtering — registered by
 * McpServerFactory ahead of the SDK handler (custom request handlers win),
 * mirroring its pagination and dropping invisible prompts from the page.
 *
 * @implements RequestHandlerInterface<ListPromptsResult>
 *
 * @internal wired by {@see \Rasuvaeff\Yii3Mcp\McpServerFactory}
 */
final readonly class FilteredListPromptsHandler implements RequestHandlerInterface
{
    public function __construct(
        private RegistryInterface $registry,
        private PromptVisibilityInterface $visibility,
        private int $pageSize,
    ) {}

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request instanceof ListPromptsRequest;
    }

    /**
     * @return Response<ListPromptsResult>
     */
    #[\Override]
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof ListPromptsRequest);

        $page = $this->registry->getPrompts($this->pageSize, $request->cursor);

        $visible = [];

        foreach ($page->references as $prompt) {
            if ($prompt instanceof Prompt && $this->visibility->isVisible($prompt, $session)) {
                $visible[] = $prompt;
            }
        }

        return new Response(
            $request->getId(),
            new ListPromptsResult($visible, $page->nextCursor),
        );
    }
}
