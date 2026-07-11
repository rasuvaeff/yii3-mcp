<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Visibility;

use InvalidArgumentException;
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;

/**
 * Tool visibility from declarative deny/allow name patterns — the typical
 * "hide admin tools from this endpoint" case without writing a
 * ToolVisibilityInterface class. Patterns are tool names with `*` matching
 * any run of characters (`admin.*`, `*.delete`).
 *
 * Deny wins over allow; a non-empty allow list hides everything it does not
 * match; an empty allow list allows everything not denied. The rules are
 * session-independent — implement ToolVisibilityInterface directly for
 * per-session logic.
 *
 * @api
 */
final readonly class DeclarativeToolVisibility implements ToolVisibilityInterface
{
    /** @var list<non-empty-string> */
    private array $denyPatterns;

    /** @var list<non-empty-string> */
    private array $allowPatterns;

    /**
     * @param list<string> $deny tool-name patterns to hide (`*` is a wildcard)
     * @param list<string> $allow when non-empty, only matching tools stay visible
     */
    public function __construct(array $deny = [], array $allow = [])
    {
        $this->denyPatterns = self::compile($deny);
        $this->allowPatterns = self::compile($allow);
    }

    #[\Override]
    public function isVisible(Tool $tool, ?SessionInterface $session): bool
    {
        foreach ($this->denyPatterns as $pattern) {
            if (preg_match($pattern, $tool->name) === 1) {
                return false;
            }
        }

        if ($this->allowPatterns === []) {
            return true;
        }

        foreach ($this->allowPatterns as $pattern) {
            if (preg_match($pattern, $tool->name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<non-empty-string>
     */
    private static function compile(array $patterns): array
    {
        $compiled = [];

        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                throw new InvalidArgumentException('Visibility pattern must not be empty');
            }

            $compiled[] = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
        }

        return $compiled;
    }
}
