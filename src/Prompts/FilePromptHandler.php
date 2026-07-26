<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Prompts;

use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\PromptHandlerInterface;
use RuntimeException;

/**
 * Serves a Markdown prompt: substitutes `{{name}}` placeholders for the
 * declared arguments (missing ones become empty strings, undeclared
 * placeholders are left intact — vjik/my-prompts-mcp semantics).
 *
 * Substitution amplifies caller input: one argument value is inserted at
 * EVERY occurrence of its placeholder, so a template with N occurrences
 * multiplies the caller's bytes by N. The result size is therefore computed
 * arithmetically and checked against `$maxResultBytes` BEFORE the substituted
 * string is built — an over-budget prompt fails without the allocation it is
 * refusing.
 *
 * @internal
 */
final readonly class FilePromptHandler implements PromptHandlerInterface
{
    /**
     * @param list<string> $argumentNames
     * @param int $maxResultBytes upper bound on the substituted prompt text (0 = unlimited)
     */
    public function __construct(
        private string $content,
        private array $argumentNames,
        private string $promptName = '',
        private int $maxResultBytes = MarkdownPromptsConfigurator::DEFAULT_MAX_RESULT_BYTES,
    ) {
        if ($maxResultBytes < 0) {
            throw new \InvalidArgumentException(sprintf('Max result bytes must not be negative, %d given', $maxResultBytes));
        }
    }

    #[\Override]
    public function get(array $arguments, ClientGateway $gateway): mixed
    {
        $pairs = [];

        foreach ($this->argumentNames as $name) {
            $pairs['{{' . $name . '}}'] = is_scalar($arguments[$name] ?? null) ? (string) $arguments[$name] : '';
        }

        $this->assertWithinBudget($pairs);

        $text = $pairs === [] ? $this->content : strtr($this->content, $pairs);

        return new PromptMessage(role: Role::User, content: new TextContent($text));
    }

    /**
     * strtr() is a single left-to-right pass (an inserted value is never
     * re-scanned) and the placeholders are distinct literals, so the exact
     * result size is plain arithmetic over occurrence counts — no
     * substitution needed to know it.
     *
     * @param array<string, string> $pairs
     */
    private function assertWithinBudget(array $pairs): void
    {
        if ($this->maxResultBytes === 0) {
            return;
        }

        $size = strlen($this->content);

        foreach ($pairs as $placeholder => $value) {
            $size += substr_count($this->content, $placeholder) * (strlen($value) - strlen($placeholder));
        }

        if ($size > $this->maxResultBytes) {
            throw new RuntimeException(sprintf(
                'Prompt "%s" would expand to %d bytes, over the %d-byte limit',
                $this->promptName,
                $size,
                $this->maxResultBytes,
            ));
        }
    }
}
