<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;
use Mcp\Schema\Extension\Apps\UiToolMeta;

/**
 * The attribute-based MCP App path: a `ui://` resource whose descriptor
 * carries the extension marker and whose content carries the sandbox
 * contract, plus a tool linked to it.
 */
final readonly class DashboardAppTool
{
    #[McpResource(
        uri: 'ui://dashboard',
        name: 'dashboard',
        mimeType: McpApps::MIME_TYPE,
        meta: ['ui' => new \stdClass()],
    )]
    public function dashboard(): TextResourceContents
    {
        return new TextResourceContents(
            uri: 'ui://dashboard',
            mimeType: McpApps::MIME_TYPE,
            text: '<!DOCTYPE html><h1>Attribute dashboard</h1>',
            meta: ['ui' => new UiResourceContentMeta(
                csp: new UiResourceCsp(connectDomains: ['api.example.com']),
                prefersBorder: true,
            )],
        );
    }

    #[McpTool(
        name: 'refresh_dashboard',
        meta: ['ui' => new UiToolMeta(resourceUri: 'ui://dashboard')],
    )]
    public function refresh(): string
    {
        return 'refreshed';
    }
}
