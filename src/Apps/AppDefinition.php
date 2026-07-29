<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Apps;

use Closure;
use InvalidArgumentException;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;

/**
 * One MCP App (`io.modelcontextprotocol/ui`): an interactive HTML document
 * served as a `ui://` resource and rendered by the client in a sandboxed
 * iframe.
 *
 * The HTML is either a fixed string or a `Closure(): string` evaluated on
 * every `resources/read` — a closure is the hook for templating, DI-provided
 * data or per-request state, and its cost is paid per read.
 *
 * `$contentMeta` carries the sandbox contract (CSP allow-lists, permissions,
 * domain, border preference) and belongs to the resource CONTENT; the
 * descriptor in `resources/list` only ever carries the extension's marker
 * ({@see McpApps::resourceMarker()}), which {@see McpAppsConfigurator} adds.
 * Leaving it `null` is valid: the host then applies its own restrictive
 * default policy.
 *
 * @api
 */
final readonly class AppDefinition
{
    /**
     * @param string|Closure(): string $html
     */
    private function __construct(
        public string $uri,
        public string $name,
        public string|Closure $html,
        public ?string $title,
        public ?string $description,
        public ?UiResourceContentMeta $contentMeta,
    ) {
        $prefix = McpApps::URI_SCHEME . '://';

        if (!str_starts_with($uri, $prefix) || strlen($uri) === strlen($prefix)) {
            throw new InvalidArgumentException(sprintf('Invalid app URI "%s": must start with "%s" and name a resource', $uri, $prefix));
        }

        if ($name === '') {
            throw new InvalidArgumentException('App name must not be empty');
        }
    }

    /**
     * @param string|Closure(): string $html fixed markup, or a factory called on every read
     */
    public static function create(
        string $uri,
        string $name,
        string|Closure $html,
        ?string $title = null,
        ?string $description = null,
        ?UiResourceContentMeta $contentMeta = null,
    ): self {
        return new self(
            uri: $uri,
            name: $name,
            html: $html,
            title: $title,
            description: $description,
            contentMeta: $contentMeta,
        );
    }

    /**
     * The markup to serve now: a closure is re-evaluated on every call, a
     * string is returned as given.
     */
    public function renderHtml(): string
    {
        return is_string($this->html) ? $this->html : ($this->html)();
    }
}
