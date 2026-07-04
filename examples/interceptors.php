<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\Interceptor\SessionBudgetInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Yiisoft\Test\Support\Container\SimpleContainer;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class CounterTool
{
    /**
     * Returns a counter value.
     */
    #[McpTool(name: 'counter.next')]
    public function next(int $current): int
    {
        return $current + 1;
    }
}

// A tracing interceptor: sees every tools/call (attribute tools, OpenAPI
// bridge, configurators), may inspect the context and decide to call $next.
// In an application: params 'interceptors' => [TracingInterceptor::class].
final readonly class TracingInterceptor implements ToolCallInterceptorInterface
{
    #[\Override]
    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        $client = $context->getClientInfo()['name'] ?? 'unknown';
        echo "trace: {$client} calls {$context->toolName}(" . json_encode($context->arguments) . ")\n";

        $result = $next();

        echo "trace: {$context->toolName} -> " . json_encode($result) . "\n";

        return $result;
    }
}

$factory = new Psr17Factory();
$server = (new McpServerFactory(
    container: new SimpleContainer([CounterTool::class => new CounterTool()]),
    sessionStore: new InMemorySessionStore(),
    name: 'interceptors-example',
    version: '1.0.0',
))->create(
    [CounterTool::class],
    [],
    // first = outermost: the budget guard rejects before tracing does work
    // (in an application: params 'session' => ['budget' => 2] wires the
    // budget interceptor automatically)
    [new SessionBudgetInterceptor(budget: 2), new TracingInterceptor()],
);

$tester = new McpTester($server, $factory, $factory, $factory);

echo $tester->callTool('counter.next', ['current' => 1])['content'][0]['text'] . "\n";
echo $tester->callTool('counter.next', ['current' => 2])['content'][0]['text'] . "\n";

// third call: session budget of 2 is exhausted -> MCP tool-error envelope
$result = $tester->callTool('counter.next', ['current' => 3]);
echo 'third call isError=' . var_export($result['isError'], true) . ': ' . $result['content'][0]['text'] . "\n";
