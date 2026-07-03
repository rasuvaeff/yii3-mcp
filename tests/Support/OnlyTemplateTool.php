<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpResourceTemplate;

final readonly class OnlyTemplateTool
{
    #[McpResourceTemplate(uriTemplate: 'app://items/{id}', name: 'item')]
    public function read(string $id): string
    {
        return 'item-' . $id;
    }
}
