<?php

declare(strict_types=1);

use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Prompts\MarkdownPromptsConfigurator;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Yiisoft\Test\Support\Container\SimpleContainer;

require dirname(__DIR__) . '/vendor/autoload.php';

// Every *.md file in the directory becomes an MCP prompt: frontmatter carries
// name/title/description/arguments, the body is the template with {{argument}}
// placeholders. In an application this is one params line:
// 'rasuvaeff/yii3-mcp' => ['prompts_path' => __DIR__ . '/prompts'].
$server = (new McpServerFactory(
    container: new SimpleContainer(),
    sessionStore: new InMemorySessionStore(),
    name: 'prompts-example',
    version: '1.0.0',
))->create([], [new MarkdownPromptsConfigurator(__DIR__ . '/prompts')]);

$factory = new Psr17Factory();
$tester = new McpTester($server, $factory, $factory, $factory);

// 1. prompts/list — what the agent sees
foreach ($tester->request('prompts/list')['prompts'] as $prompt) {
    $arguments = implode(', ', array_map(
        static fn(array $argument): string => $argument['name'] . (($argument['required'] ?? false) ? '*' : ''),
        $prompt['arguments'] ?? [],
    ));
    echo "prompt: {$prompt['name']} ({$arguments})\n";
}

// 2. prompts/get — placeholders are substituted from the arguments
$result = $tester->request('prompts/get', [
    'name' => 'commit-message',
    'arguments' => ['diff' => '- old line\n+ new line', 'scope' => ' with scope "docs"'],
]);
echo "rendered:\n" . $result['messages'][0]['content']['text'] . "\n";
