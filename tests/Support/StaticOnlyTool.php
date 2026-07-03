<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpTool;

final readonly class StaticOnlyTool
{
    #[McpTool(name: 'static-op')]
    public static function staticOp(): string
    {
        return 'static';
    }
}
