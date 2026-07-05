<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpTool;

final class CountingTool
{
    public int $calls = 0;

    /**
     * Counts invocations.
     */
    #[McpTool(name: 'count.up')]
    public function up(): int
    {
        return ++$this->calls;
    }
}
