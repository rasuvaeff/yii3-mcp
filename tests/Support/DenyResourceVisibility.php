<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Server\Session\SessionInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ResourceVisibilityInterface;

final readonly class DenyResourceVisibility implements ResourceVisibilityInterface
{
    /**
     * @param list<string> $hiddenUris resource URIs invisible to every session
     * @param list<string> $hiddenTemplates URI templates invisible to every session
     */
    public function __construct(
        private array $hiddenUris = [],
        private array $hiddenTemplates = [],
    ) {}

    #[\Override]
    public function isVisible(ResourceDefinition $resource, ?SessionInterface $session): bool
    {
        return !in_array($resource->uri, $this->hiddenUris, strict: true);
    }

    #[\Override]
    public function isTemplateVisible(ResourceTemplate $template, ?SessionInterface $session): bool
    {
        return !in_array($template->uriTemplate, $this->hiddenTemplates, strict: true);
    }
}
