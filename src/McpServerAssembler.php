<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Mcp\Server;

/**
 * @internal
 */
final readonly class McpServerAssembler
{
    public function __construct(
        private McpServerFactory $factory,
        private McpServerComponents $components,
    ) {}

    public function create(): Server
    {
        return $this->factory->create(
            toolClasses: $this->components->tools,
            configurators: $this->components->configurators,
            interceptors: $this->components->interceptors,
            toolVisibility: $this->components->visibility,
            promptInterceptors: $this->components->promptInterceptors,
            resourceInterceptors: $this->components->resourceInterceptors,
            promptVisibility: $this->components->promptVisibility,
            resourceVisibility: $this->components->resourceVisibility,
        );
    }
}
