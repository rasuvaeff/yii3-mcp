<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResourceTemplate;

final readonly class DualTemplatePromptTool
{
    #[McpResourceTemplate(uriTemplate: 'app://dual/{id}', name: 'dual-template')]
    #[McpPrompt(name: 'dual-prompt')]
    public function dual(string $id = ''): string
    {
        return 'dual-' . $id;
    }
}
