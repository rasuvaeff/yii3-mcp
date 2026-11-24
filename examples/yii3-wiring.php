<?php

declare(strict_types=1);

use Mcp\Server\Capability\Attribute\McpTool;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Mcp\McpAction;
use Rasuvaeff\Mcp\McpListCommand;
use Rasuvaeff\Mcp\SharedSecretMiddleware;
use Rasuvaeff\Yii3Mcp\Doctor\McpDoctor;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class OrderRepository
{
    public function status(string $orderId): string
    {
        return $orderId === '42' ? 'shipped' : 'unknown';
    }
}

final readonly class OrderTools
{
    public function __construct(
        private OrderRepository $orders,
    ) {}

    /**
     * Returns the current status of an order.
     */
    #[McpTool(name: 'order.status')]
    public function status(string $orderId): string
    {
        return $this->orders->status($orderId);
    }
}

// What yiisoft/config does in an application: the package params, overridden by
// the application's, handed to the package DI definitions.
$params = array_replace_recursive(require dirname(__DIR__) . '/config/params.php', [
    'rasuvaeff/yii3-mcp' => [
        'server_name' => 'my-app',
        'tools' => [OrderTools::class],
        'endpoint_secret' => 'example-secret',
        'visibility' => ['deny' => ['admin.*']],
    ],
]);
$definitions = (static fn(array $params): array => require dirname(__DIR__) . '/config/di.php')($params);

$container = new Container(ContainerConfig::create()->withDefinitions([
    ...$definitions,
    // the application binds PSR-17
    ResponseFactoryInterface::class => Psr17Factory::class,
    StreamFactoryInterface::class => Psr17Factory::class,
    ServerRequestFactoryInterface::class => Psr17Factory::class,
]));

// 1. the endpoint, the way a route runs it: SharedSecretMiddleware -> McpAction
$call = static fn(array $headers): string => (static function () use ($container, $headers): string {
    $response = $container->get(SharedSecretMiddleware::class)->process(
        new ServerRequest('POST', 'http://localhost/mcp', [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            ...$headers,
        ], json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'example', 'version' => '1.0']],
        ], JSON_THROW_ON_ERROR)),
        $container->get(McpAction::class),
    );

    return (string) $response->getStatusCode();
})();

echo 'initialize without the secret -> ', $call([]), "\n";
echo 'initialize with the secret    -> ', $call(['X-Mcp-Secret' => 'example-secret']), "\n\n";

// 2. mcp:list — what is served
$list = new CommandTester($container->get(McpListCommand::class));
$list->execute([]);
echo $list->getDisplay(), "\n";

// 3. mcp:doctor — the checks behind the command
foreach ($container->get(McpDoctor::class)->diagnose()->checks as $check) {
    printf("%-22s %s\n", $check->name, $check->status->value);
}
