<?php

declare(strict_types=1);

use Mcp\Schema\Tool;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\OpenApi\HttpOperationExecutor;
use Rasuvaeff\Yii3Mcp\OpenApi\OpenApiServerConfigurator;
use Rasuvaeff\Yii3Mcp\OpenApi\Operation;
use Rasuvaeff\Yii3Mcp\OpenApi\OperationModifierInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Yiisoft\Test\Support\Container\SimpleContainer;

require dirname(__DIR__) . '/vendor/autoload.php';

// Allow-listed OpenAPI operations become MCP tools: name = operationId,
// description = summary, inputSchema from parameters. Calls are executed as
// real HTTP requests against the API (its middleware stack applies). Here the
// PSR-18 client is a stub so the example runs offline; in an application it
// comes from the container (params 'openapi' => [...] wires everything).
$spec = SpecIndex::fromJson(json_encode([
    'openapi' => '3.0.0',
    'info' => ['title' => 'Blog API', 'version' => '1.0.0'],
    'paths' => [
        '/blog-tags' => [
            'get' => [
                'operationId' => 'getBlogTags',
                'summary' => 'List blog tags',
                'tags' => ['catalog'],   // propagated into the tool's _meta
                'parameters' => [
                    ['name' => 'locale', 'in' => 'query', 'schema' => ['type' => 'string']],
                ],
            ],
        ],
        '/blog-tag/{slug}' => [
            'get' => [
                'operationId' => 'getBlogTagBySlug',
                'summary' => 'One blog tag',
                'parameters' => [
                    ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                // object-typed 2xx response => advertised as the MCP tool
                // outputSchema; the JSON payload arrives as structuredContent
                'responses' => [
                    '200' => ['content' => ['application/json' => ['schema' => [
                        'type' => 'object',
                        'properties' => ['slug' => ['type' => 'string'], 'title' => ['type' => 'string']],
                        'required' => ['slug'],
                    ]]]],
                ],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));

$httpClient = new class implements ClientInterface {
    public ?RequestInterface $lastRequest = null;

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;
        $body = str_contains($request->getUri()->getPath(), '/blog-tag/')
            ? '{"slug":"php","title":"PHP"}'
            : '[{"slug":"php"},{"slug":"yii3"}]';

        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }
};

// Per-operation customization, applied after the tool_names rename below —
// here it appends a note to the description; a name change would be
// validated and checked for collisions exactly like a tool_names rename.
$modifier = new class implements OperationModifierInterface {
    #[\Override]
    public function modify(Operation $operation, Tool $tool): Tool
    {
        return new Tool(
            name: $tool->name,
            title: $tool->title,
            inputSchema: $tool->inputSchema,
            description: $tool->description . ' (via OpenAPI bridge)',
            annotations: $tool->annotations,
            meta: $tool->meta,
            outputSchema: $tool->outputSchema,
        );
    }
};

$factory = new Psr17Factory();
$server = (new McpServerFactory(
    container: new SimpleContainer(),
    sessionStore: new InMemorySessionStore(),
    name: 'openapi-example',
    version: '1.0.0',
))->create([], [
    new OpenApiServerConfigurator(
        spec: $spec,
        executor: new HttpOperationExecutor(
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.example.com',
            defaultHeaders: ['Authorization' => 'Bearer demo-token'],
        ),
        operations: ['getBlogTags', 'getBlogTagBySlug'],   // allow-list: everything else stays hidden
        safeMethodsOnly: true,         // non-GET in the list would fail the build
        toolNames: ['getBlogTags' => 'blog_tags_list'],   // LLM-friendlier than the raw operationId
        modifier: $modifier,
        dryRunOperations: ['getBlogTagBySlug'],   // adds a `dryRun` boolean argument to this tool only
    ),
]);

$tester = new McpTester($server, $factory, $factory, $factory);

foreach ($tester->listTools() as $tool) {
    $schema = isset($tool['outputSchema'])
        ? ' [outputSchema: ' . implode(', ', array_keys($tool['outputSchema']['properties'] ?? [])) . ']'
        : '';
    $readOnly = ($tool['annotations']['readOnlyHint'] ?? false) ? ' [readOnlyHint]' : '';
    $tags = isset($tool['_meta']['rasuvaeff/yii3-mcp']['tags'])
        ? ' [tags: ' . implode(', ', $tool['_meta']['rasuvaeff/yii3-mcp']['tags']) . ']'
        : '';
    echo "tool: {$tool['name']} — {$tool['description']}{$schema}{$readOnly}{$tags}\n";
}

// renamed by tool_names; the original operationId is no longer a valid tool name
$result = $tester->callTool('blog_tags_list', ['locale' => 'ru']);
echo 'upstream request: ' . $httpClient->lastRequest?->getUri() . "\n";
echo 'result: ' . $result['content'][0]['text'] . "\n";

$result = $tester->callTool('getBlogTagBySlug', ['slug' => 'php']);
echo 'structuredContent: ' . json_encode($result['structuredContent'] ?? null) . "\n";

// dryRun: true previews the request without sending it — no HTTP call, no
// upstream credentials leave the process (headers are never in the preview)
$dryRun = $tester->callTool('getBlogTagBySlug', ['slug' => 'php', 'dryRun' => true]);
echo 'dry-run plan: ' . $dryRun['content'][0]['text'] . "\n";
