<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Closure;
use LogicException;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyListVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingConfigurator;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingInterceptor;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function sessionStoreDefaultsToFpmSafeFileStore(): void
    {
        /** @var array{definition: Closure} $definition */
        $definition = $this->di()[SessionStoreInterface::class];

        Assert::instanceOf($definition['definition'](), FileSessionStore::class);
    }

    public function serverDefinitionBuildsFromFactoryAndParamsTools(): void
    {
        /** @var Closure $definition */
        $definition = $this->di()[Server::class]['definition'];

        $factory = new McpServerFactory(
            container: new SimpleContainer([]),
            sessionStore: new InMemorySessionStore(),
        );

        Assert::instanceOf($definition($factory, new SimpleContainer([])), Server::class);
    }

    public function serverDefinitionRegistersConfiguredTools(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tools'] = [GreetingTool::class];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $factory = new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hi')]),
            sessionStore: new InMemorySessionStore(),
        );

        Assert::instanceOf($definition($factory, new SimpleContainer([])), Server::class);
    }

    public function budgetAndInterceptorsAreOffByDefault(): void
    {
        $params = $this->params();

        /** @var array{session: array{budget: int}, interceptors: list<class-string>} $mcp */
        $mcp = $params['rasuvaeff/yii3-mcp'];

        Assert::same($mcp['session']['budget'], 0);
        Assert::same($mcp['interceptors'], []);
        Assert::same($mcp['configurators'], []);
        Assert::same($params['rasuvaeff/yii3-mcp']['tool_visibility'], '');
        Assert::same($params['rasuvaeff/yii3-mcp']['visibility'], ['deny' => [], 'allow' => []]);
    }

    public function serverDefinitionWiresToolVisibility(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tools'] = [GreetingTool::class];
        $params['rasuvaeff/yii3-mcp']['tool_visibility'] = DenyListVisibility::class;

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $container = new SimpleContainer([
            GreetingTool::class => new GreetingTool(prefix: 'Hi'),
            DenyListVisibility::class => new DenyListVisibility(hidden: ['explode']),
        ]);
        $factory = new McpServerFactory(
            container: $container,
            sessionStore: new InMemorySessionStore(),
        );

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);

        Assert::same(array_column($tester->listTools(), 'name'), ['greet']);
    }

    public function serverDefinitionWiresDeclarativeVisibility(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tools'] = [GreetingTool::class];
        $params['rasuvaeff/yii3-mcp']['visibility'] = ['deny' => ['expl*'], 'allow' => []];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $container = new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hi')]);
        $factory = new McpServerFactory(
            container: $container,
            sessionStore: new InMemorySessionStore(),
        );

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);

        Assert::same(array_column($tester->listTools(), 'name'), ['greet']);
    }

    public function serverDefinitionRejectsBothVisibilityKinds(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tool_visibility'] = DenyListVisibility::class;
        $params['rasuvaeff/yii3-mcp']['visibility'] = ['deny' => ['admin.*'], 'allow' => []];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $factory = new McpServerFactory(
            container: new SimpleContainer([]),
            sessionStore: new InMemorySessionStore(),
        );

        Expect::exception(LogicException::class);

        $definition($factory, new SimpleContainer([]));
    }

    public function serverDefinitionWiresBudgetAndConfiguredInterceptors(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tools'] = [GreetingTool::class];
        $params['rasuvaeff/yii3-mcp']['session']['budget'] = 3;
        $params['rasuvaeff/yii3-mcp']['interceptors'] = [RecordingInterceptor::class];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $recording = new RecordingInterceptor();
        $container = new SimpleContainer([
            GreetingTool::class => new GreetingTool(prefix: 'Hi'),
            RecordingInterceptor::class => $recording,
        ]);
        $factory = new McpServerFactory(
            container: $container,
            sessionStore: new InMemorySessionStore(),
        );

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);
        $tester->callTool('greet', ['name' => 'Yii']);

        // the configured interceptor actually ran → both budget guard and
        // params-listed interceptors are wired into the chain
        Assert::same($recording->entries, ['interceptor:before:greet', 'interceptor:after:greet']);
    }

    public function serverDefinitionWiresConfiguredConfigurators(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tools'] = [GreetingTool::class];
        $params['rasuvaeff/yii3-mcp']['configurators'] = [RecordingConfigurator::class];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $configurator = new RecordingConfigurator();
        $container = new SimpleContainer([
            GreetingTool::class => new GreetingTool(prefix: 'Hi'),
            RecordingConfigurator::class => $configurator,
        ]);
        $factory = new McpServerFactory(
            container: $container,
            sessionStore: new InMemorySessionStore(),
        );

        /** @var Server $server */
        $server = $definition($factory, $container);

        // the params-listed configurator ran against the builder before build
        Assert::true($configurator->configured);
        Assert::instanceOf($server, Server::class);
    }

    public function actionDefinitionUsesFqcnKeyAndEmptyAllowedHosts(): void
    {
        /** @var array{'__construct()': array{allowedHosts: list<string>}} $definition */
        $definition = $this->di()[\Rasuvaeff\Yii3Mcp\McpAction::class];

        Assert::same($definition['__construct()']['allowedHosts'], []);
    }

    public function middlewareDefinitionCarriesFailClosedDefaults(): void
    {
        /** @var array{'__construct()': array{secret: string, headerName: string}} $definition */
        $definition = $this->di()[SharedSecretMiddleware::class];

        // empty by default: instantiating the middleware without an explicit
        // secret must throw (fail-closed), so the shipped default is ''
        Assert::same($definition['__construct()']['secret'], '');
        Assert::same($definition['__construct()']['headerName'], 'X-Mcp-Secret');
    }

    /**
     * @return array<string, mixed>
     */
    private function params(): array
    {
        return require dirname(__DIR__) . '/config/params.php';
    }

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    private function di(?array $params = null): array
    {
        $params ??= $this->params();

        return (static fn(array $params): array => require dirname(__DIR__) . '/config/di.php')($params);
    }
}
