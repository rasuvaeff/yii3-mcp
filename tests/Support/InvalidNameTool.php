<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpTool;

final readonly class InvalidNameTool
{
    /**
     * Registers under a name the SDK NameValidator warns about.
     */
    #[McpTool(name: 'bad name!')]
    public function bad(): string
    {
        return 'bad';
    }
}
