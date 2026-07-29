<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;
use Mcp\Schema\Extension\Apps\UiResourcePermissions;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\Apps\AppDefinition;
use Rasuvaeff\Yii3Mcp\Apps\McpAppsConfigurator;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Yiisoft\Test\Support\Container\SimpleContainer;

require dirname(__DIR__) . '/vendor/autoload.php';

// MCP Apps (io.modelcontextprotocol/ui): HTML applications the client renders
// in a sandboxed iframe inside the conversation. Two registration paths, both
// shown below; both need the extension enabled, which McpAppsConfigurator does.

// --- path 2: attribute-based, for apps with logic behind them ---------------
final readonly class ReportApp
{
    #[McpResource(
        uri: 'ui://report',
        name: 'report',
        title: 'Live report',
        mimeType: McpApps::MIME_TYPE,
        // the DESCRIPTOR only ever carries the bare marker
        meta: ['ui' => new \stdClass()],
    )]
    public function report(): TextResourceContents
    {
        // rendered per read: pull data from the container, a repository, …
        $rows = ['Berlin' => 42, 'Lisbon' => 17];
        $items = '';

        foreach ($rows as $city => $value) {
            $items .= sprintf('<li>%s: %d</li>', $city, $value);
        }

        return new TextResourceContents(
            uri: 'ui://report',
            mimeType: McpApps::MIME_TYPE,
            text: '<!DOCTYPE html><h1>Report</h1><ul>' . $items . '</ul>',
            // the CONTENT carries the sandbox contract
            meta: ['ui' => new UiResourceContentMeta(
                csp: new UiResourceCsp(connectDomains: ['api.example.com']),
                prefersBorder: true,
            )],
        );
    }

    // a tool linked to the app: the host can call it from the rendered HTML
    #[McpTool(
        name: 'refresh_report',
        description: 'Recomputes the report shown in the ui://report app',
        meta: ['ui' => new UiToolMeta(resourceUri: 'ui://report')],
    )]
    public function refresh(): string
    {
        return 'refreshed';
    }
}

// --- path 1: declarative, for static or templated apps ----------------------
// In an application this is params, not PHP:
// 'rasuvaeff/yii3-mcp' => ['apps' => ['definitions' => [[
//     'uri' => 'ui://dashboard', 'name' => 'dashboard', 'html' => '…',
//     'csp' => ['connect_domains' => ['api.example.com']],
//     'permissions' => ['geolocation' => true],
// ]]]]
$dashboard = AppDefinition::create(
    uri: 'ui://dashboard',
    name: 'dashboard',
    // a Closure is re-evaluated on every read — the hook for templating
    html: static fn(): string => '<!DOCTYPE html><h1>Sales</h1><p>Rendered at read time</p>',
    title: 'Dashboard',
    description: 'Sales overview',
    contentMeta: new UiResourceContentMeta(
        csp: new UiResourceCsp(connectDomains: ['api.example.com']),
        permissions: new UiResourcePermissions(geolocation: true),
        prefersBorder: true,
    ),
);

$server = (new McpServerFactory(
    container: new SimpleContainer([ReportApp::class => new ReportApp()]),
    sessionStore: new InMemorySessionStore(),
    name: 'apps-example',
    version: '1.0.0',
))->create([ReportApp::class], [new McpAppsConfigurator([$dashboard])]);

$factory = new Psr17Factory();
$tester = new McpTester($server, $factory, $factory, $factory);

// 1. the extension is announced during the handshake — that is what makes a
//    ui:// resource an app instead of a text file to the client
$extensions = $tester->initialize()['capabilities']['extensions'] ?? [];
echo 'extensions: ' . implode(', ', array_keys($extensions)) . "\n";

// 2. resources/list — descriptors carry the marker. McpTester decodes the
//    response associatively, so the spec's `{}` marker prints back as `[]`;
//    on the wire it is an empty JSON object.
foreach ($tester->listResources() as $resource) {
    echo sprintf(
        "resource: %s (%s) _meta.ui=%s\n",
        $resource['uri'],
        $resource['mimeType'] ?? '-',
        json_encode($resource['_meta']['ui'] ?? null, JSON_THROW_ON_ERROR),
    );
}

// 3. resources/read — content carries the HTML and the sandbox contract
foreach (['ui://dashboard', 'ui://report'] as $uri) {
    $content = $tester->readResource($uri)['contents'][0];
    echo sprintf(
        "%s → %s\n  _meta.ui=%s\n",
        $uri,
        $content['text'],
        json_encode($content['_meta']['ui'] ?? null, JSON_THROW_ON_ERROR),
    );
}

// 4. a tool linked to the app
foreach ($tester->listTools() as $tool) {
    echo sprintf(
        "tool: %s → app %s\n",
        $tool['name'],
        $tool['_meta']['ui']['resourceUri'] ?? '-',
    );
}
