<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Visibility;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListResourcesRequest;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * resources/list with per-session visibility filtering — registered by
 * McpServerFactory ahead of the SDK handler (custom request handlers win),
 * mirroring its pagination and dropping invisible resources from the page.
 *
 * @implements RequestHandlerInterface<ListResourcesResult>
 *
 * @internal wired by {@see \Rasuvaeff\Yii3Mcp\McpServerFactory}
 */
final readonly class FilteredListResourcesHandler implements RequestHandlerInterface
{
    public function __construct(
        private RegistryInterface $registry,
        private ResourceVisibilityInterface $visibility,
        private int $pageSize,
    ) {}

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request instanceof ListResourcesRequest;
    }

    /**
     * @return Response<ListResourcesResult>
     */
    #[\Override]
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof ListResourcesRequest);

        $page = $this->registry->getResources($this->pageSize, $request->cursor);

        $visible = [];

        foreach ($page->references as $resource) {
            if ($resource instanceof ResourceDefinition && $this->visibility->isVisible($resource, $session)) {
                $visible[] = $resource;
            }
        }

        return new Response(
            $request->getId(),
            new ListResourcesResult($visible, $page->nextCursor),
        );
    }
}
