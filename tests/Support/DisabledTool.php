<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpTool;
use Rasuvaeff\Yii3Mcp\ConditionalToolInterface;

final readonly class DisabledTool implements ConditionalToolInterface
{
    public function __construct(
        private bool $enabled = false,
    ) {}

    #[\Override]
    public function shouldRegister(): bool
    {
        return $this->enabled;
    }

    #[McpTool(name: 'hidden')]
    public function hidden(): string
    {
        return 'should not be visible';
    }
}
