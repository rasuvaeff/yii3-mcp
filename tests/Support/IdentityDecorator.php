<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Schema\Result\CallToolResult;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolResultDecoratorInterface;

/**
 * Returns the result untouched: its presence alone must not change a byte
 * of what the client receives.
 */
final class IdentityDecorator implements ToolResultDecoratorInterface
{
    public int $calls = 0;

    #[\Override]
    public function decorate(CallToolResult $result, ToolCallContext $context): CallToolResult
    {
        ++$this->calls;

        return $result;
    }
}
