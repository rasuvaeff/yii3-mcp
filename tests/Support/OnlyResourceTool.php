<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpResource;

final readonly class OnlyResourceTool
{
    #[McpResource(uri: 'app://only-resource', name: 'only-resource')]
    public function read(): string
    {
        return 'resource-data';
    }
}
