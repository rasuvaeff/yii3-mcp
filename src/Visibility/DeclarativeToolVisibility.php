<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Visibility;

use InvalidArgumentException;
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;

/**
 * Tool visibility from declarative deny/allow patterns — the typical "hide
 * admin tools from this endpoint" case without writing a
 * ToolVisibilityInterface class. A pattern matches the tool name with `*`
 * matching any run of characters (`admin.*`, `*.delete`); a `tag:` prefix
 * matches instead against the tool's tags (e.g. the OpenAPI bridge's
 * `tag:admin-*`, propagated from OpenAPI `tags` into the tool's `_meta`) —
 * a tool with no tags never matches a `tag:` pattern.
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
    private const string TAG_PREFIX = 'tag:';

    /** @var list<array{kind: 'name'|'tag', regex: non-empty-string}> */
    private array $denyPatterns;

    /** @var list<array{kind: 'name'|'tag', regex: non-empty-string}> */
    private array $allowPatterns;

    /**
     * @param list<string> $deny patterns to hide (`*` is a wildcard; `tag:` prefix matches tags)
     * @param list<string> $allow when non-empty, only matching tools stay visible
     */
    public function __construct(array $deny = [], array $allow = [])
    {
        $this->denyPatterns = $this->compile($deny);
        $this->allowPatterns = $this->compile($allow);
    }

    #[\Override]
    public function isVisible(Tool $tool, ?SessionInterface $session): bool
    {
        if ($this->matchesAny($this->denyPatterns, $tool)) {
            return false;
        }

        if ($this->allowPatterns === []) {
            return true;
        }

        return $this->matchesAny($this->allowPatterns, $tool);
    }

    /**
     * @param list<array{kind: 'name'|'tag', regex: non-empty-string}> $patterns
     */
    private function matchesAny(array $patterns, Tool $tool): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern['kind'] === 'name') {
                if (preg_match($pattern['regex'], $tool->name) === 1) {
                    return true;
                }

                continue;
            }

            foreach ($this->tagsOf($tool) as $tag) {
                if (preg_match($pattern['regex'], $tag) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function tagsOf(Tool $tool): array
    {
        /** @var mixed $ownMeta */
        $ownMeta = $tool->meta['rasuvaeff/yii3-mcp'] ?? null;
        /** @var mixed $tags */
        $tags = is_array($ownMeta) ? ($ownMeta['tags'] ?? null) : null;

        if (!is_array($tags)) {
            return [];
        }

        $named = [];

        /** @var mixed $tag */
        foreach ($tags as $tag) {
            if (is_string($tag)) {
                $named[] = $tag;
            }
        }

        return $named;
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<array{kind: 'name'|'tag', regex: non-empty-string}>
     */
    private function compile(array $patterns): array
    {
        $compiled = [];

        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                throw new InvalidArgumentException('Visibility pattern must not be empty');
            }

            if (str_starts_with($pattern, self::TAG_PREFIX)) {
                $tagPattern = substr($pattern, strlen(self::TAG_PREFIX));

                if ($tagPattern === '') {
                    throw new InvalidArgumentException('Visibility pattern must not be empty');
                }

                $compiled[] = ['kind' => 'tag', 'regex' => $this->toRegex($tagPattern)];

                continue;
            }

            $compiled[] = ['kind' => 'name', 'regex' => $this->toRegex($pattern)];
        }

        return $compiled;
    }

    /**
     * @return non-empty-string
     */
    private function toRegex(string $pattern): string
    {
        return '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '\z/';
    }
}
