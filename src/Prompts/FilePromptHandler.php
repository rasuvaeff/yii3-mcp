<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Prompts;

use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\PromptHandlerInterface;

/**
 * Serves a Markdown prompt: substitutes `{{name}}` placeholders for the
 * declared arguments (missing ones become empty strings, undeclared
 * placeholders are left intact — vjik/my-prompts-mcp semantics).
 *
 * @internal
 */
final readonly class FilePromptHandler implements PromptHandlerInterface
{
    /**
     * @param list<string> $argumentNames
     */
    public function __construct(
        private string $content,
        private array $argumentNames,
    ) {}

    #[\Override]
    public function get(array $arguments, ClientGateway $gateway): mixed
    {
        $pairs = [];

        foreach ($this->argumentNames as $name) {
            $pairs['{{' . $name . '}}'] = is_scalar($arguments[$name] ?? null) ? (string) $arguments[$name] : '';
        }

        $text = $pairs === [] ? $this->content : strtr($this->content, $pairs);

        return new PromptMessage(role: Role::User, content: new TextContent($text));
    }
}
