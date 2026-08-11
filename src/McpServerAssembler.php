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
            $this->components->tools,
            $this->components->configurators,
            $this->components->interceptors,
            $this->components->visibility,
            $this->components->promptInterceptors,
            $this->components->resourceInterceptors,
            $this->components->promptVisibility,
            $this->components->resourceVisibility,
        );
    }
}
