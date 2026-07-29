<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Apps;

use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ResourceHandlerInterface;

/**
 * Serves one {@see AppDefinition} on `resources/read`.
 *
 * Returns `TextResourceContents` rather than a raw string on purpose: the
 * SDK's resource formatter only carries `_meta` through when the handler
 * hands it a ready `ResourceContents`, and `_meta.ui` (CSP, permissions) is
 * the app's sandbox contract. A `ReadResourceResult` would NOT work here —
 * the formatter has no branch for it and throws on the unhandled type.
 *
 * @internal wired by {@see McpAppsConfigurator}
 */
final readonly class AppResourceHandler implements ResourceHandlerInterface
{
    public function __construct(
        private AppDefinition $app,
    ) {}

    #[\Override]
    public function read(string $uri, ClientGateway $gateway): mixed
    {
        $meta = $this->app->contentMeta;

        return new TextResourceContents(
            uri: $this->app->uri,
            mimeType: McpApps::MIME_TYPE,
            text: $this->app->renderHtml(),
            meta: $meta instanceof UiResourceContentMeta ? ['ui' => $meta] : null,
        );
    }
}
