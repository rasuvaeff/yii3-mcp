<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\Interceptor\ArgumentMasker;
use Rasuvaeff\Yii3Mcp\Interceptor\ResponseSizeLimitInterceptor;
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

    /**
     * Signs the session in.
     */
    #[McpTool(name: 'auth.login')]
    public function login(string $user, string $password): string
    {
        return $password === '' ? 'denied' : 'welcome, ' . $user;
    }

    /**
     * Returns a result far bigger than any agent needs.
     */
    #[McpTool(name: 'report.dump')]
    public function dump(): string
    {
        return str_repeat('data ', 1_000);
    }
}

// A tracing interceptor: sees every tools/call (attribute tools, OpenAPI
// bridge, configurators), may inspect the context and decide to call $next.
// Arguments leave the process (a log line here), so they go through
// ArgumentMasker first — sensitive keys become '***' at every nesting level.
// In an application: params 'interceptors' => [TracingInterceptor::class].
final readonly class TracingInterceptor implements ToolCallInterceptorInterface
{
    public function __construct(
        private ArgumentMasker $masker = new ArgumentMasker(),
    ) {}

    #[\Override]
    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        $client = $context->getClientInfo()['name'] ?? 'unknown';
        echo "trace: {$client} calls {$context->toolName}(" . json_encode($this->masker->mask($context->arguments)) . ")\n";

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
    // first = outermost: the budget guard rejects before tracing does work;
    // the size limit goes last (innermost, closest to the tool) — in an
    // application: params 'session' => ['budget' => 3] and
    // 'limits' => ['tool_result_bytes' => 100] wire both automatically, in
    // this same relative order
    [new SessionBudgetInterceptor(budget: 3), new TracingInterceptor(), new ResponseSizeLimitInterceptor(maxBytes: 100)],
);

$tester = new McpTester($server, $factory, $factory, $factory);

// the trace line shows "password":"***" — the tool still gets the real value
echo $tester->callTool('auth.login', ['user' => 'alice', 'password' => 'p@ss'])['content'][0]['text'] . "\n";
echo $tester->callTool('counter.next', ['current' => 1])['content'][0]['text'] . "\n";

// report.dump returns 5000 bytes; the limit is 100 — truncated with a marker
$result = $tester->callTool('report.dump', []);
echo 'dump length=' . strlen($result['content'][0]['text']) . ': ' . $result['content'][0]['text'] . "\n";

// fourth call: session budget of 3 is exhausted -> MCP tool-error envelope
$result = $tester->callTool('counter.next', ['current' => 3]);
echo 'fourth call isError=' . var_export($result['isError'], true) . ': ' . $result['content'][0]['text'] . "\n";
