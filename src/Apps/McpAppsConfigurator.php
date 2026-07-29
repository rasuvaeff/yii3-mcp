<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Apps;

use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\ResourceDefinition;
use Mcp\Server\Builder;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;

/**
 * Enables the MCP Apps extension (`io.modelcontextprotocol/ui`) and registers
 * the declaratively configured apps as `ui://` resources.
 *
 * Enabling the extension is what makes attribute-based apps
 * (`#[McpResource]` with a `ui://` URI) visible to clients as well — an app
 * resource a client does not know is an app renders as plain text. It is
 * therefore enabled whenever `apps.enable` is on, with or without
 * declarative definitions.
 *
 * This configurator is the single enabler of the extension in this package:
 * `Builder::enableExtension()` throws on a second registration of the same
 * extension id, so an application configurator that also enables
 * {@see McpApps} while `apps.enable` is on fails the server build.
 *
 * `_meta` placement follows the spec and is not interchangeable: the
 * descriptor (`resources/list`) carries the bare marker, the content
 * (`resources/read`) carries {@see \Mcp\Schema\Extension\Apps\UiResourceContentMeta}.
 *
 * @api
 */
final readonly class McpAppsConfigurator implements ServerConfiguratorInterface
{
    /**
     * @param list<AppDefinition> $apps
     */
    public function __construct(
        private array $apps = [],
    ) {}

    #[\Override]
    public function configure(Builder $builder): void
    {
        $builder->enableExtension(new McpApps());

        foreach ($this->apps as $app) {
            $builder->add(
                definition: new ResourceDefinition(
                    uri: $app->uri,
                    name: $app->name,
                    title: $app->title,
                    description: $app->description,
                    mimeType: McpApps::MIME_TYPE,
                    meta: ['ui' => McpApps::resourceMarker()],
                ),
                handler: new AppResourceHandler($app),
            );
        }
    }
}
