<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpTool;

final readonly class UnicodeDescriptionTool
{
    /**
     * Ünïcödé description with a slash /.
     */
    #[McpTool(name: 'unicode-tool')]
    public function run(): string
    {
        return 'ok';
    }
}
