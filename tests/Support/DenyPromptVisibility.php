<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Schema\Prompt;
use Mcp\Server\Session\SessionInterface;
use Rasuvaeff\Yii3Mcp\Visibility\PromptVisibilityInterface;

final readonly class DenyPromptVisibility implements PromptVisibilityInterface
{
    /**
     * @param list<string> $hidden prompt names invisible to every session
     */
    public function __construct(
        private array $hidden = [],
    ) {}

    #[\Override]
    public function isVisible(Prompt $prompt, ?SessionInterface $session): bool
    {
        return !in_array($prompt->name, $this->hidden, strict: true);
    }
}
