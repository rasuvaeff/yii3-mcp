<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Mcp\McpAction;
use Rasuvaeff\Mcp\SharedSecretMiddleware;
use Rasuvaeff\Mcp\Testing\AdapterContract;
use Rasuvaeff\Mcp\Testing\AdapterContractTool;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

/**
 * The shared adapter contract over this package's real config and a real
 * yiisoft/di container.
 */
#[Test]
#[CoversNothing]
final class AdapterContractTest
{
    private const array SECRETS = ['alpha' => 'secret-a', 'beta' => 'secret-b'];

    private static ?AdapterContract $contract = null;

    #[DataProvider('checkProvider')]
    public function keepsTheAdapterContract(string $check): void
    {
        self::contract()->checks()[$check]();
        Assert::true(true);
    }

    public static function checkProvider(): iterable
    {
        foreach (array_keys(self::contract()->checks()) as $check) {
            yield $check => [$check];
        }
    }

    private static function contract(): AdapterContract
    {
        if (self::$contract instanceof AdapterContract) {
            return self::$contract;
        }

        $params = require __DIR__ . '/../config/params.php';
        $params['rasuvaeff/yii3-mcp']['server_name'] = 'contract-' . getmypid();
        $params['rasuvaeff/yii3-mcp']['tools'] = [AdapterContractTool::class];
        $params['rasuvaeff/yii3-mcp']['client_secrets'] = self::SECRETS;
        $factory = new Psr17Factory();
        $container = new Container(ContainerConfig::create()->withDefinitions([
            ...(static fn(array $params): array => require __DIR__ . '/../config/di.php')($params),
            ResponseFactoryInterface::class => $factory,
            StreamFactoryInterface::class => $factory,
            ServerRequestFactoryInterface::class => $factory,
        ]));

        return self::$contract = new AdapterContract(
            handle: static fn(ServerRequestInterface $request): ResponseInterface => $container->get(SharedSecretMiddleware::class)
                ->process($request, $container->get(McpAction::class)),
            requestFactory: $factory,
            streamFactory: $factory,
            uri: 'http://localhost/mcp',
            secrets: self::SECRETS,
        );
    }
}
