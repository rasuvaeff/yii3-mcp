<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Yiisoft\Test\Support\Container\SimpleContainer;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class WeatherTool
{
    /**
     * Current weather for a city.
     *
     * @return array{city: string, temperature: int, conditions: string}
     */
    #[McpTool(
        name: 'weather',
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'city' => ['type' => 'string'],
                'temperature' => ['type' => 'integer'],
                'conditions' => ['type' => 'string'],
            ],
            'required' => ['city', 'temperature', 'conditions'],
        ],
    )]
    public function weather(string $city): array
    {
        return ['city' => $city, 'temperature' => 21, 'conditions' => 'sunny'];
    }
}

$factory = new Psr17Factory();
$server = (new McpServerFactory(
    container: new SimpleContainer([WeatherTool::class => new WeatherTool()]),
    sessionStore: new InMemorySessionStore(),
    name: 'structured-output-example',
    version: '1.0.0',
))->create([WeatherTool::class]);

$tester = new McpTester($server, $factory, $factory, $factory);

// tools/list serves the declared outputSchema — the agent knows the result
// shape before the first call
$tool = $tester->listTools()[0];
echo 'outputSchema.required: ' . implode(', ', $tool['outputSchema']['required']) . "\n";

// the array return is mirrored into structuredContent alongside the
// human-readable text content
$result = $tester->callTool('weather', ['city' => 'Kazan']);
echo 'structuredContent: ' . json_encode($result['structuredContent']) . "\n";
echo 'text content: ' . $result['content'][0]['text'] . "\n";
