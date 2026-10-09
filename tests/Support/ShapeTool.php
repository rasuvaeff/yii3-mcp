<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * Returns every result shape the SDK formats differently — the matrix a
 * result decorator must not change anything in by its mere presence.
 */
final class ShapeTool
{
    public const array KINDS = ['assoc', 'list', 'empty', 'string', 'int', 'null', 'bool', 'content', 'mixed', 'ready', 'ready_error'];

    #[McpTool(name: 'shape')]
    public function shape(string $kind): mixed
    {
        return match ($kind) {
            'assoc' => ['city' => 'Rome', 'temperature' => 21],
            'list' => [['slug' => 'a'], ['slug' => 'b']],
            'empty' => [],
            'string' => 'plain',
            'int' => 42,
            'null' => null,
            'bool' => false,
            'content' => new TextContent('content item'),
            'mixed' => [new TextContent('first'), ['nested' => true]],
            'ready' => new CallToolResult([new TextContent('ready-made')]),
            'ready_error' => CallToolResult::error([new TextContent('handled failure')]),
            default => throw new ToolCallException('Unknown shape "' . $kind . '"'),
        };
    }
}
