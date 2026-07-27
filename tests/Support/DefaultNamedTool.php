<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpTool;

/**
 * Both #[McpTool] attributes omit the name, so the SDK derives it: the
 * method name normally, the class short name for __invoke.
 */
final readonly class DefaultNamedTool
{
    #[McpTool]
    public function lookup(): string
    {
        return 'looked up';
    }

    #[McpTool]
    public function __invoke(): string
    {
        return 'invoked';
    }
}
