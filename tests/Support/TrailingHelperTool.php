<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpTool;

final readonly class TrailingHelperTool
{
    #[McpTool(name: 'real-op')]
    public function realOp(): string
    {
        return 'real';
    }

    public function helperWithoutAttribute(): string
    {
        return 'helper';
    }
}
