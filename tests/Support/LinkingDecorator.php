<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Result\CallToolResult;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolResultDecoratorInterface;

/**
 * Appends one ResourceLink named after itself and records what it saw.
 */
final class LinkingDecorator implements ToolResultDecoratorInterface
{
    /** @var list<array{tool: string, arguments: array<string, mixed>, isError: bool, content: int}> */
    public array $seen = [];

    public function __construct(
        private readonly string $name = 'link',
    ) {}

    #[\Override]
    public function decorate(CallToolResult $result, ToolCallContext $context): CallToolResult
    {
        $this->seen[] = ['tool' => $context->toolName, 'arguments' => $context->arguments, 'isError' => $result->isError, 'content' => count($result->content)];

        return new CallToolResult(
            [...$result->content, new ResourceLink(uri: 'app://' . $this->name, name: $this->name)],
            $result->isError,
            $result->structuredContent,
            $result->meta,
        );
    }
}
