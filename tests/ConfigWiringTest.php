<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Closure;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
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
