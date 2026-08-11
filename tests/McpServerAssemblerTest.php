<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Rasuvaeff\Yii3Mcp\McpServerAssembler;
use Rasuvaeff\Yii3Mcp\McpServerComponentResolver;
use Rasuvaeff\Yii3Mcp\McpServerComponents;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyPromptVisibility;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(McpServerAssembler::class)]
#[Covers(McpServerComponentResolver::class)]
#[Covers(McpServerComponents::class)]
final class McpServerAssemblerTest
{
    public function assemblerBuildsFromResolvedComponentsWithoutAContainerDependency(): void
    {
        $container = new SimpleContainer([]);
        $components = new McpServerComponents(
            tools: [],
            configurators: [],
            interceptors: [],
            visibility: null,
            promptInterceptors: [],
            resourceInterceptors: [],
            promptVisibility: null,
            resourceVisibility: null,
        );
        $assembler = new McpServerAssembler(
            factory: new McpServerFactory(
                container: $container,
                sessionStore: new InMemorySessionStore(),
            ),
            components: $components,
        );

        Assert::instanceOf($assembler->create(), Server::class);
    }

    public function resolverPassesAUserConfiguredVisibilityAsAReadyComponent(): void
    {
        $visibility = new DenyPromptVisibility(hidden: ['private']);
        $params = $this->params();
        $params['prompt_visibility'] = DenyPromptVisibility::class;
        $resolver = new McpServerComponentResolver(
            container: new SimpleContainer([DenyPromptVisibility::class => $visibility]),
            params: $params,
        );

        Assert::same($resolver->resolve()->promptVisibility, $visibility);
    }

    /**
     * @return array<string, mixed>
     */
    private function params(): array
    {
        /** @var array<string, array<string, mixed>> $params */
        $params = require dirname(__DIR__) . '/config/params.php';

        return $params['rasuvaeff/yii3-mcp'];
    }
}
