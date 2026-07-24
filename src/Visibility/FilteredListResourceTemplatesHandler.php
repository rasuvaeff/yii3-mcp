<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Visibility;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListResourceTemplatesRequest;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Result\ListResourceTemplatesResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * resources/templates/list with per-session visibility filtering —
 * registered by McpServerFactory ahead of the SDK handler (custom request
 * handlers win), mirroring its pagination and dropping invisible templates
 * from the page.
 *
 * @implements RequestHandlerInterface<ListResourceTemplatesResult>
 *
 * @internal wired by {@see \Rasuvaeff\Yii3Mcp\McpServerFactory}
 */
final readonly class FilteredListResourceTemplatesHandler implements RequestHandlerInterface
{
    public function __construct(
        private RegistryInterface $registry,
        private ResourceVisibilityInterface $visibility,
        private int $pageSize,
    ) {}

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request instanceof ListResourceTemplatesRequest;
    }

    /**
     * @return Response<ListResourceTemplatesResult>
     */
    #[\Override]
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof ListResourceTemplatesRequest);

        $page = $this->registry->getResourceTemplates($this->pageSize, $request->cursor);

        $visible = [];

        foreach ($page->references as $template) {
            if ($template instanceof ResourceTemplate && $this->visibility->isTemplateVisible($template, $session)) {
                $visible[] = $template;
            }
        }

        return new Response(
            $request->getId(),
            new ListResourceTemplatesResult($visible, $page->nextCursor),
        );
    }
}
