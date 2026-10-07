<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * A tool whose method already returns a ready CallToolResult — the shape the
 * SDK (and the compact-mode conversion) must pass through untouched.
 */
final readonly class ReadyResultTool
{
    #[McpTool(name: 'ready')]
    public function ready(): CallToolResult
    {
        return new CallToolResult([new TextContent('ready-made')]);
    }
}
