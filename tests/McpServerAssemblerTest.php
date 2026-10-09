<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Mcp\McpServerAssembler;
use Rasuvaeff\Yii3Mcp\McpServerComponentResolver;
use Rasuvaeff\Yii3Mcp\McpServerComponents;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyPromptVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyResourceVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\IdentityDecorator;
use Rasuvaeff\Yii3Mcp\Tests\Support\LinkingDecorator;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingPromptInterceptor;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingResourceInterceptor;
use Rasuvaeff\Yii3Mcp\Visibility\DeclarativeToolVisibility;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

use function Rasuvaeff\Understudy\verify;

#[Test]
#[Covers(McpServerAssembler::class)]
#[Covers(McpServerComponentResolver::class)]
#[Covers(McpServerComponents::class)]
final class McpServerAssemblerTest
{
    public function assemblerBuildsFromResolvedComponentsWithoutAContainerDependency(): void
    {
        $configurator = Understudy::for(ServerConfiguratorInterface::class);
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
        verify(fn() => $configurator->configure(Arg::any()));

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

    public function resolverPassesConfiguredResultDecoratorsInOrder(): void
    {
        $first = new LinkingDecorator(name: 'first');
        $params = $this->params();
        $params['result_decorators'] = [LinkingDecorator::class, IdentityDecorator::class];
        $identity = new IdentityDecorator();
        $resolver = new McpServerComponentResolver(
            container: new SimpleContainer([LinkingDecorator::class => $first, IdentityDecorator::class => $identity]),
            params: $params,
        );

        Assert::same($resolver->resolve()->resultDecorators, [$first, $identity]);
    }

    public function aResultDecoratorThatIsNoDecoratorFailsTheBuild(): void
    {
        $params = $this->params();
        $params['result_decorators'] = [GreetingTool::class];
        $resolver = new McpServerComponentResolver(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hi')]),
            params: $params,
        );

        try {
            $resolver->resolve();
            $error = null;
        } catch (\LogicException $error) {
        }

        Assert::instanceOf($error, \LogicException::class);
        Assert::string($error->getMessage())->contains('must implement Rasuvaeff\\Yii3Mcp\\Interceptor\\ToolResultDecoratorInterface');
    }

    public function assemblerHandsResultDecoratorsToTheServer(): void
    {
        $components = new McpServerComponents(
            tools: [GreetingTool::class],
            configurators: [],
            interceptors: [],
            visibility: null,
            promptInterceptors: [],
            resourceInterceptors: [],
            promptVisibility: null,
            resourceVisibility: null,
            resultDecorators: [new LinkingDecorator()],
        );
        $server = (new McpServerAssembler(
            factory: new McpServerFactory(
                container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hi')]),
                sessionStore: new InMemorySessionStore(),
            ),
            components: $components,
        ))->create();
        $psr17 = new Psr17Factory();

        $result = (new McpTester(server: $server, requestFactory: $psr17, responseFactory: $psr17, streamFactory: $psr17))->callTool('greet', ['name' => 'Yii']);

        Assert::same($result['content'][1]['uri'] ?? null, 'app://link');
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
        $params = require __DIR__ . '/../config/params.php';

        return $params['rasuvaeff/yii3-mcp'];
    }
}
