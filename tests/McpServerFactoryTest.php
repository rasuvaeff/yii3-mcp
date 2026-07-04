<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\Exception\InvalidToolClassException;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\AttributelessClass;
use Rasuvaeff\Yii3Mcp\Tests\Support\ConstructorAttributeTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\DisabledTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\DualTemplatePromptTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\DualToolResourceTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\OnlyPromptTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\OnlyResourceTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\OnlyTemplateTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingInterceptor;
use Rasuvaeff\Yii3Mcp\Tests\Support\StaticOnlyTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\TrailingHelperTool;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(McpServerFactory::class)]
final class McpServerFactoryTest
{
    public function buildsServerFromAttributedToolClass(): void
    {
        $server = $this->factory()->create([GreetingTool::class]);

        Assert::instanceOf($server, Server::class);
    }

    public function buildsServerWithoutTools(): void
    {
        Assert::instanceOf($this->factory()->create([]), Server::class);
    }

    public function throwsOnUnknownClass(): void
    {
        Expect::exception(InvalidToolClassException::class);

        $this->factory()->create(['App\\Missing\\Tool']);
    }

    public function throwsOnClassWithoutCapabilityAttributes(): void
    {
        Expect::exception(InvalidToolClassException::class);

        $this->factory()->create([AttributelessClass::class]);
    }

    public function conditionalToolOptingOutIsSkippedWithoutError(): void
    {
        $server = $this->factory()->create([DisabledTool::class]);

        Assert::same(array_column($this->tester($server)->listTools(), 'name'), []);
    }

    public function conditionalToolOptingInExposesItsTools(): void
    {
        $factory = new McpServerFactory(
            container: new SimpleContainer([
                DisabledTool::class => new DisabledTool(enabled: true),
            ]),
            sessionStore: new InMemorySessionStore(),
        );

        $server = $factory->create([DisabledTool::class]);

        Assert::same(array_column($this->tester($server)->listTools(), 'name'), ['hidden']);
    }

    public function allFourCapabilityTypesAreExposed(): void
    {
        $tester = $this->tester($this->factory()->create([GreetingTool::class]));

        $toolNames = array_column($tester->listTools(), 'name');
        sort($toolNames);
        Assert::same($toolNames, ['explode', 'greet']);

        $resources = $this->names($tester->request('resources/list'), 'resources');
        Assert::same($resources, ['status']);

        $templates = array_column(
            array_filter((array) ($tester->request('resources/templates/list')['resourceTemplates'] ?? []), is_array(...)),
            'uriTemplate',
        );
        Assert::same($templates, ['app://users/{id}']);

        Assert::same($this->names($tester->request('prompts/list'), 'prompts'), ['greeting-style']);
    }

    #[DataProvider('singleCapabilityProvider')]
    public function eachCapabilityTypeAloneCountsAsRegistered(string $class): void
    {
        Assert::instanceOf($this->factory()->create([$class]), Server::class);
    }

    public static function singleCapabilityProvider(): iterable
    {
        yield 'resource only' => [OnlyResourceTool::class];
        yield 'resource template only' => [OnlyTemplateTool::class];
        yield 'prompt only' => [OnlyPromptTool::class];
    }

    #[DataProvider('dualAttributeProvider')]
    public function dualAttributeMethodsCountBothCapabilities(string $class): void
    {
        Assert::instanceOf($this->factory()->create([$class]), Server::class);
    }

    public static function dualAttributeProvider(): iterable
    {
        yield 'tool + resource on one method' => [DualToolResourceTool::class];
        yield 'template + prompt on one method' => [DualTemplatePromptTool::class];
    }

    public function staticMethodsAreNotCapabilities(): void
    {
        Expect::exception(InvalidToolClassException::class);

        $this->factory()->create([StaticOnlyTool::class]);
    }

    public function constructorAttributesAreNotCapabilities(): void
    {
        Expect::exception(InvalidToolClassException::class);

        $this->factory()->create([ConstructorAttributeTool::class]);
    }

    public function attributelessTrailingMethodDoesNotResetTheCount(): void
    {
        Assert::instanceOf($this->factory()->create([TrailingHelperTool::class]), Server::class);
    }

    public function configuratorsContributeToTheBuilder(): void
    {
        $configurator = new class implements ServerConfiguratorInterface {
            #[\Override]
            public function configure(Builder $builder): void
            {
                $builder->addTool(
                    handler: static fn(): string => 'from-configurator',
                    name: 'configured-tool',
                );
            }
        };

        $server = $this->factory()->create([], [$configurator]);

        Assert::same(array_column($this->tester($server)->listTools(), 'name'), ['configured-tool']);
    }

    public function interceptorsWrapToolCallsBuiltByTheFactory(): void
    {
        $recording = new RecordingInterceptor();

        $server = $this->factory()->create([GreetingTool::class], [], [$recording]);
        $this->tester($server)->callTool('greet', ['name' => 'Yii']);

        Assert::same($recording->entries, ['interceptor:before:greet', 'interceptor:after:greet']);
    }

    public function withoutInterceptorsToolCallsStillWork(): void
    {
        $server = $this->factory()->create([GreetingTool::class], [], []);

        $result = $this->tester($server)->callTool('greet', ['name' => 'Yii']);

        Assert::same($result['content'][0]['text'], 'Hello, Yii!');
    }

    private function tester(Server $server): McpTester
    {
        $psr17 = new Psr17Factory();

        return new McpTester(server: $server, requestFactory: $psr17, responseFactory: $psr17, streamFactory: $psr17);
    }

    /**
     * @param array<array-key, mixed> $result
     *
     * @return list<mixed>
     */
    private function names(array $result, string $key): array
    {
        return array_column(array_filter((array) ($result[$key] ?? []), is_array(...)), 'name');
    }

    private function factory(): McpServerFactory
    {
        return new McpServerFactory(
            container: new SimpleContainer([
                GreetingTool::class => new GreetingTool(prefix: 'Hello'),
                DisabledTool::class => new DisabledTool(enabled: false),
            ]),
            sessionStore: new InMemorySessionStore(),
            name: 'test-server',
            version: '1.0.0',
        );
    }
}
