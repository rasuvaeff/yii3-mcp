<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Visibility;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * tools/list with per-session visibility filtering — registered by
 * McpServerFactory ahead of the SDK handler (custom request handlers win),
 * mirroring its pagination and dropping invisible tools from the page.
 *
 * @implements RequestHandlerInterface<ListToolsResult>
 *
 * @internal wired by {@see \Rasuvaeff\Yii3Mcp\McpServerFactory}
 */
final readonly class FilteredListToolsHandler implements RequestHandlerInterface
{
    public function __construct(
        private RegistryInterface $registry,
        private ToolVisibilityInterface $visibility,
        private int $pageSize,
    ) {}

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest;
    }

    /**
     * @return Response<ListToolsResult>
     */
    #[\Override]
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof ListToolsRequest);

        $page = $this->registry->getTools($this->pageSize, $request->cursor);

        $visible = [];

        foreach ($page->references as $tool) {
            if ($tool instanceof Tool && $this->visibility->isVisible($tool, $session)) {
                $visible[] = $tool;
            }
        }

        return new Response(
            $request->getId(),
            new ListToolsResult($visible, $page->nextCursor),
        );
    }
}
