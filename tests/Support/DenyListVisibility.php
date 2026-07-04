<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;

final readonly class DenyListVisibility implements ToolVisibilityInterface
{
    /**
     * @param list<string> $hidden tool names invisible to every session
     * @param string $hiddenForClient tool name invisible only when the session's client bears this name
     */
    public function __construct(
        private array $hidden = [],
        private string $hiddenForClient = '',
    ) {}

    #[\Override]
    public function isVisible(Tool $tool, ?SessionInterface $session): bool
    {
        if (in_array($tool->name, $this->hidden, strict: true)) {
            return false;
        }

        if ($this->hiddenForClient !== '' && $session instanceof SessionInterface) {
            /** @var mixed $info */
            $info = $session->get('client_info');
            $client = is_array($info) && is_string($info['name'] ?? null) ? $info['name'] : '';

            if ($client === $this->hiddenForClient) {
                return false;
            }
        }

        return true;
    }
}
