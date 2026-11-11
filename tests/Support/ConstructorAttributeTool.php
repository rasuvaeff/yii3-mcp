<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Server\Capability\Attribute\McpTool;

final readonly class ConstructorAttributeTool
{
    #[McpTool(name: 'ctor-op')]
    public function __construct() {}
}
