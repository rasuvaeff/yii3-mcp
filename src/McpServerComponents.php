<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Rasuvaeff\Yii3Mcp\Interceptor\PromptGetInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\ResourceReadInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Visibility\PromptVisibilityInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ResourceVisibilityInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;

/**
 * @internal
 */
final readonly class McpServerComponents
{
    /**
     * @param list<class-string> $tools
     * @param list<ServerConfiguratorInterface> $configurators
     * @param list<ToolCallInterceptorInterface> $interceptors
     * @param list<PromptGetInterceptorInterface> $promptInterceptors
     * @param list<ResourceReadInterceptorInterface> $resourceInterceptors
     */
    public function __construct(
        public array $tools,
        public array $configurators,
        public array $interceptors,
        public ?ToolVisibilityInterface $visibility,
        public array $promptInterceptors,
        public array $resourceInterceptors,
        public ?PromptVisibilityInterface $promptVisibility,
        public ?ResourceVisibilityInterface $resourceVisibility,
    ) {}
}
