<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpPrompt;

final readonly class OnlyPromptTool
{
    #[McpPrompt(name: 'only-prompt')]
    public function prompt(): string
    {
        return 'prompt-text';
    }
}
