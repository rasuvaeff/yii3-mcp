<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;

final readonly class DualToolResourceTool
{
    #[McpTool(name: 'dual-op')]
    #[McpResource(uri: 'app://dual', name: 'dual')]
    public function dual(): string
    {
        return 'dual';
    }
}
