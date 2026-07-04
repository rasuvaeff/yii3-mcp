<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Transport\StdioTransport;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Yiisoft\Test\Support\Container\SimpleContainer;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class TimeTool
{
    /**
     * Returns the current server time.
     */
    #[McpTool(name: 'time.now')]
    public function now(): string
    {
        return date(DATE_ATOM);
    }
}

$server = (new McpServerFactory(
    container: new SimpleContainer([TimeTool::class => new TimeTool()]),
    sessionStore: new InMemorySessionStore(),
    name: 'stdio-example',
    version: '1.0.0',
))->create([TimeTool::class]);

// This is exactly what `./yii mcp:serve` (McpServeCommand) does, except the
// command reads real STDIN/STDOUT — an MCP client (Claude Code, Claude
// Desktop) launches the process and speaks line-delimited JSON-RPC with it:
//   claude mcp add my-app -- ./yii mcp:serve
// Here the streams are in-memory so the example runs (and terminates) offline.
$input = fopen('php://memory', 'r+');
$outputPath = tempnam(sys_get_temp_dir(), 'mcp-stdio-');
$output = fopen($outputPath, 'w+');

foreach ([
    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
        'protocolVersion' => '2025-06-18',
        'capabilities' => [],
        'clientInfo' => ['name' => 'example-client', 'version' => '1.0'],
    ]],
    ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
    ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'time.now', 'arguments' => new stdClass()]],
] as $message) {
    fwrite($input, json_encode($message, JSON_THROW_ON_ERROR) . "\n");
}

rewind($input);

$server->run(new StdioTransport(input: $input, output: $output));

// the transport closes its streams when it stops — read the file instead
$responses = trim(file_get_contents($outputPath));
unlink($outputPath);

foreach (explode("\n", $responses) as $line) {
    $decoded = json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR);
    echo isset($decoded['result']['serverInfo'])
        ? "initialize -> server: {$decoded['result']['serverInfo']['name']}\n"
        : "tools/call -> {$decoded['result']['content'][0]['text']}\n";
}
