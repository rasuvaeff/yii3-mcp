<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerAssembler;
use Rasuvaeff\Yii3Mcp\McpServerComponentResolver;
use Rasuvaeff\Yii3Mcp\McpServerComponents;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyPromptVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyResourceVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingConfigurator;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingPromptInterceptor;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingResourceInterceptor;
use Rasuvaeff\Yii3Mcp\Visibility\DeclarativeToolVisibility;
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
        $configurator = new RecordingConfigurator();
        $components = new McpServerComponents(
            tools: [GreetingTool::class],
            configurators: [$configurator],
            interceptors: [],
            visibility: null,
            promptInterceptors: [],
            resourceInterceptors: [],
            promptVisibility: null,
            resourceVisibility: null,
        );
        $assembler = new McpServerAssembler(
            factory: new McpServerFactory(
                container: new SimpleContainer([]),
                sessionStore: new InMemorySessionStore(),
            ),
            components: $components,
        );

        $server = $assembler->create();

        Assert::instanceOf($server, Server::class);
        Assert::true($configurator->configured);

        $psr17 = new Psr17Factory();
        $tester = new McpTester(
            server: $server,
            requestFactory: $psr17,
            responseFactory: $psr17,
            streamFactory: $psr17,
        );
        $names = array_column($tester->listTools(), 'name');
        sort($names);

        Assert::same($names, ['explode', 'greet']);
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

    public function resolverPassesConfiguredResourceVisibilityAsAReadyComponent(): void
    {
        $visibility = new DenyResourceVisibility(hiddenUris: ['file:///private']);
        $params = $this->params();
        $params['resource_visibility'] = DenyResourceVisibility::class;
        $resolver = new McpServerComponentResolver(
            container: new SimpleContainer([DenyResourceVisibility::class => $visibility]),
            params: $params,
        );

        Assert::same($resolver->resolve()->resourceVisibility, $visibility);
    }

    public function resolverPassesConfiguredPromptInterceptorsAsReadyComponents(): void
    {
        $interceptor = new RecordingPromptInterceptor();
        $params = $this->params();
        $params['prompt_interceptors'] = [RecordingPromptInterceptor::class];
        $resolver = new McpServerComponentResolver(
            container: new SimpleContainer([RecordingPromptInterceptor::class => $interceptor]),
            params: $params,
        );

        Assert::same($resolver->resolve()->promptInterceptors, [$interceptor]);
    }

    public function resolverPassesConfiguredResourceInterceptorsAsReadyComponents(): void
    {
        $interceptor = new RecordingResourceInterceptor();
        $params = $this->params();
        $params['resource_interceptors'] = [RecordingResourceInterceptor::class];
        $resolver = new McpServerComponentResolver(
            container: new SimpleContainer([RecordingResourceInterceptor::class => $interceptor]),
            params: $params,
        );

        Assert::same($resolver->resolve()->resourceInterceptors, [$interceptor]);
    }

    public function anAllowListAloneStillBuildsDeclarativeToolVisibility(): void
    {
        $params = $this->params();
        $params['visibility']['allow'] = ['greet'];
        $resolver = new McpServerComponentResolver(container: new SimpleContainer([]), params: $params);

        Assert::instanceOf($resolver->resolve()->visibility, DeclarativeToolVisibility::class);
    }

    public function bothDeclarativeListsTogetherStillBuildToolVisibility(): void
    {
        $params = $this->params();
        $params['visibility']['deny'] = ['internal'];
        $params['visibility']['allow'] = ['greet'];
        $resolver = new McpServerComponentResolver(container: new SimpleContainer([]), params: $params);

        Assert::instanceOf($resolver->resolve()->visibility, DeclarativeToolVisibility::class);
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
