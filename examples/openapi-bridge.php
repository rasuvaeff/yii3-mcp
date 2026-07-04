<?php

declare(strict_types=1);

use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\OpenApi\HttpOperationExecutor;
use Rasuvaeff\Yii3Mcp\OpenApi\OpenApiServerConfigurator;
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
                'parameters' => [
                    ['name' => 'locale', 'in' => 'query', 'schema' => ['type' => 'string']],
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

        return new Response(200, ['Content-Type' => 'application/json'], '[{"slug":"php"},{"slug":"yii3"}]');
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
        operations: ['getBlogTags'],   // allow-list: everything else stays hidden
        safeMethodsOnly: true,         // non-GET in the list would fail the build
    ),
]);

$tester = new McpTester($server, $factory, $factory, $factory);

foreach ($tester->listTools() as $tool) {
    echo "tool: {$tool['name']} — {$tool['description']}\n";
}

$result = $tester->callTool('getBlogTags', ['locale' => 'ru']);
echo 'upstream request: ' . $httpClient->lastRequest?->getUri() . "\n";
echo 'result: ' . $result['content'][0]['text'] . "\n";
