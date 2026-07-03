<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Yii3Mcp\McpAction;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Yiisoft\Test\Support\Container\SimpleContainer;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class EchoTool
{
    /**
     * Echoes the given text back.
     */
    #[McpTool(name: 'echo')]
    public function echo(string $text): string
    {
        return 'echo: ' . $text;
    }
}

$factory = new Psr17Factory();
$server = (new McpServerFactory(
    container: new SimpleContainer([EchoTool::class => new EchoTool()]),
    sessionStore: new InMemorySessionStore(),
    name: 'example-server',
    version: '1.0.0',
))->create([EchoTool::class]);

$action = new McpAction(server: $server, responseFactory: $factory, streamFactory: $factory);

$post = static fn(array $payload, string $sessionId = ''): ServerRequest => (new ServerRequest(
    'POST',
    '/mcp',
    ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'],
    json_encode($payload, JSON_THROW_ON_ERROR),
))->withHeader('Mcp-Session-Id', $sessionId);

// 1. initialize
$response = $action->handle($post([
    'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
    'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'example', 'version' => '1.0']],
]));
$sessionId = $response->getHeaderLine('Mcp-Session-Id');
echo "initialize -> {$response->getStatusCode()}, session={$sessionId}\n";

// 2. tools/call
$response = $action->handle($post([
    'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
    'params' => ['name' => 'echo', 'arguments' => ['text' => 'hello mcp']],
], $sessionId));
preg_match('/^data: (.*)$/m', (string) $response->getBody(), $m);
$body = json_decode($m[1] ?? (string) $response->getBody(), true);
echo 'tools/call -> ' . $body['result']['content'][0]['text'] . "\n";
