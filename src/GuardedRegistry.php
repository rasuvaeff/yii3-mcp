<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Rasuvaeff\Yii3Mcp\Exception\DuplicateCapabilityException;

/**
 * Registry decorator making capability identity collisions a build-time
 * error. The SDK registry is last-write-wins with no duplicate check, and
 * its explicit loader runs before the reflected one — so on a collision one
 * registration silently disappears from the served set while every rule
 * keyed by its name (visibility, cache, RBAC, audit) keeps "working" against
 * the wrong handler. {@see McpServerFactory} installs this guard on every
 * server it builds, covering ALL registration paths at the single point they
 * converge: attribute tools, configurators, OpenAPI-bridged operations and
 * Markdown prompts alike. Re-registering after an explicit unregister stays
 * allowed — only a duplicate among live registrations throws.
 *
 * @internal
 */
final readonly class GuardedRegistry implements RegistryInterface
{
    public function __construct(
        private RegistryInterface $inner,
    ) {}

    #[\Override]
    public function registerTool(Tool $tool, callable|array|string $handler): ToolReference
    {
        if ($this->inner->hasTool($tool->name)) {
            throw new DuplicateCapabilityException(sprintf('Tool "%s" is already registered; capability names must be unique across the whole server', $tool->name));
        }

        return $this->inner->registerTool($tool, $handler);
    }

    #[\Override]
    public function registerResource(ResourceDefinition $resource, callable|array|string $handler): ResourceReference
    {
        if ($this->inner->hasResource($resource->uri)) {
            throw new DuplicateCapabilityException(sprintf('Resource "%s" is already registered; capability URIs must be unique across the whole server', $resource->uri));
        }

        return $this->inner->registerResource($resource, $handler);
    }

    /**
     * @param \Closure|array{0: object|string, 1: string}|string $handler
     * @param array<string, class-string|object> $completionProviders
     */
    #[\Override]
    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
    ): ResourceTemplateReference {
        if ($this->inner->hasResourceTemplate($template->uriTemplate)) {
            throw new DuplicateCapabilityException(sprintf('Resource template "%s" is already registered; capability URI templates must be unique across the whole server', $template->uriTemplate));
        }

        return $this->inner->registerResourceTemplate($template, $handler, $completionProviders);
    }

    /**
     * @param \Closure|array{0: object|string, 1: string}|string $handler
     * @param array<string, class-string|object> $completionProviders
     */
    #[\Override]
    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
    ): PromptReference {
        if ($this->inner->hasPrompt($prompt->name)) {
            throw new DuplicateCapabilityException(sprintf('Prompt "%s" is already registered; capability names must be unique across the whole server', $prompt->name));
        }

        return $this->inner->registerPrompt($prompt, $handler, $completionProviders);
    }

    #[\Override]
    public function unregisterTool(string $name): void
    {
        $this->inner->unregisterTool($name);
    }

    #[\Override]
    public function unregisterResource(string $uri): void
    {
        $this->inner->unregisterResource($uri);
    }

    #[\Override]
    public function unregisterResourceTemplate(string $uriTemplate): void
    {
        $this->inner->unregisterResourceTemplate($uriTemplate);
    }

    #[\Override]
    public function unregisterPrompt(string $name): void
    {
        $this->inner->unregisterPrompt($name);
    }

    #[\Override]
    public function hasTool(string $name): bool
    {
        return $this->inner->hasTool($name);
    }

    #[\Override]
    public function hasResource(string $uri): bool
    {
        return $this->inner->hasResource($uri);
    }

    #[\Override]
    public function hasResourceTemplate(string $uriTemplate): bool
    {
        return $this->inner->hasResourceTemplate($uriTemplate);
    }

    #[\Override]
    public function hasPrompt(string $name): bool
    {
        return $this->inner->hasPrompt($name);
    }

    #[\Override]
    public function hasTools(): bool
    {
        return $this->inner->hasTools();
    }

    #[\Override]
    public function getTools(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getTools($limit, $cursor);
    }

    #[\Override]
    public function getTool(string $name): ToolReference
    {
        return $this->inner->getTool($name);
    }

    #[\Override]
    public function hasResources(): bool
    {
        return $this->inner->hasResources();
    }

    #[\Override]
    public function getResources(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getResources($limit, $cursor);
    }

    #[\Override]
    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference
    {
        return $this->inner->getResource($uri, $includeTemplates);
    }

    #[\Override]
    public function hasResourceTemplates(): bool
    {
        return $this->inner->hasResourceTemplates();
    }

    #[\Override]
    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getResourceTemplates($limit, $cursor);
    }

    #[\Override]
    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference
    {
        return $this->inner->getResourceTemplate($uriTemplate);
    }

    #[\Override]
    public function hasPrompts(): bool
    {
        return $this->inner->hasPrompts();
    }

    #[\Override]
    public function getPrompts(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getPrompts($limit, $cursor);
    }

    #[\Override]
    public function getPrompt(string $name): PromptReference
    {
        return $this->inner->getPrompt($name);
    }
}
