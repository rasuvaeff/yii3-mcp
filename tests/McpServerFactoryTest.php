<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\Exception\InvalidToolClassException;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\AttributelessClass;
use Rasuvaeff\Yii3Mcp\Tests\Support\CompletionTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\ConstructorAttributeTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\CountingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\DefaultNamedTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyListVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyPromptVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyResourceVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\DisabledTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\DualTemplatePromptTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\DualToolResourceTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\InvalidNameTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\OnlyPromptTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\OnlyResourceTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\OnlyTemplateTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingConfigurator;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingInterceptor;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingSubscriptionManager;
use Rasuvaeff\Yii3Mcp\Tests\Support\ReservedNamesRecordingConfigurator;
use Rasuvaeff\Yii3Mcp\Tests\Support\StaticOnlyTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\StructuredWeatherTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\TrailingHelperTool;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;
use Yiisoft\Test\Support\Log\SimpleLogger;

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

    public function visibilityFiltersTheToolListing(): void
    {
        $server = $this->factory()->create([GreetingTool::class], [], [], new DenyListVisibility(hidden: ['explode']));

        // exact listing: visible tools present (the factory-owned registry IS
        // the builder's registry), hidden ones absent (the filtering handler
        // actually overrides the SDK's tools/list)
        Assert::same(array_column($this->tester($server)->listTools(), 'name'), ['greet']);
    }

    public function protocolLogsFlowThroughTheConfiguredLogger(): void
    {
        $logger = new SimpleLogger();

        $server = (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
            logger: $logger,
        ))->create([GreetingTool::class]);

        $this->tester($server)->callTool('greet', ['name' => 'Yii']);

        $executing = array_filter(
            $logger->getMessages(),
            static fn(array $message): bool => str_contains((string) $message['message'], 'Executing tool'),
        );

        Assert::false($executing === []);
    }

    public function registryWarningsReachTheConfiguredLogger(): void
    {
        $logger = new SimpleLogger();

        (new McpServerFactory(
            container: new SimpleContainer([InvalidNameTool::class => new InvalidNameTool()]),
            sessionStore: new InMemorySessionStore(),
            logger: $logger,
        ))->create([InvalidNameTool::class], [], [], new DenyListVisibility());

        $warnings = array_filter(
            $logger->getMessages(),
            static fn(array $message): bool => $message['level'] === 'warning'
                && str_contains((string) $message['message'], 'is invalid'),
        );

        Assert::false($warnings === []);
    }

    public function visibilityAloneEnforcesFailClosedCalls(): void
    {
        $server = $this->factory()->create([GreetingTool::class], [], [], new DenyListVisibility(hidden: ['greet']));

        $result = $this->tester($server)->callTool('greet', ['name' => 'Yii']);

        Assert::true($result['isError']);
        Assert::string($result['content'][0]['text'])->contains('not available in this session');
    }

    public function visibilityAndInterceptorsComposeInOneDecorator(): void
    {
        $recording = new RecordingInterceptor();

        $server = $this->factory()->create(
            [GreetingTool::class],
            [],
            [$recording],
            new DenyListVisibility(hidden: ['explode']),
        );

        $result = $this->tester($server)->callTool('greet', ['name' => 'Yii']);

        Assert::same($result['content'][0]['text'], 'Hello, Yii!');
        Assert::same($recording->entries, ['interceptor:before:greet', 'interceptor:after:greet']);
    }

    public function registersToolOutputSchemaFromAttribute(): void
    {
        $factory = new McpServerFactory(
            container: new SimpleContainer([StructuredWeatherTool::class => new StructuredWeatherTool()]),
            sessionStore: new InMemorySessionStore(),
        );
        $tester = $this->tester($factory->create([StructuredWeatherTool::class]));

        $tools = $tester->listTools();

        Assert::same($tools[0]['outputSchema']['required'] ?? null, ['city', 'temperature', 'conditions']);
    }

    public function toolWithOutputSchemaReturnsStructuredContent(): void
    {
        $factory = new McpServerFactory(
            container: new SimpleContainer([StructuredWeatherTool::class => new StructuredWeatherTool()]),
            sessionStore: new InMemorySessionStore(),
        );
        $tester = $this->tester($factory->create([StructuredWeatherTool::class]));

        $result = $tester->callTool('weather', ['city' => 'Kazan']);

        // the SDK mirrors an array return into structuredContent alongside
        // the human-readable text content
        Assert::same($result['structuredContent'] ?? null, ['city' => 'Kazan', 'temperature' => 21, 'conditions' => 'sunny']);
    }

    /**
     * The decorator is wired for EITHER filter, so resource visibility alone
     * must gate template completions too.
     */
    public function completionIsFilteredWhenResourceVisibilityIsConfigured(): void
    {
        $factory = new McpServerFactory(
            container: new SimpleContainer([CompletionTool::class => new CompletionTool()]),
            sessionStore: new InMemorySessionStore(),
        );

        $server = $factory->create(
            [CompletionTool::class],
            resourceVisibility: new DenyResourceVisibility(hiddenTemplates: ['app://reports/{region}']),
        );
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);

        $caught = null;

        try {
            $tester->request('completion/complete', [
                'ref' => ['type' => 'ref/resource', 'uri' => 'app://reports/emea'],
                'argument' => ['name' => 'region', 'value' => 'em'],
            ]);
        } catch (\RuntimeException $caught) {
        }

        Assert::notNull($caught);
    }

    /**
     * Both filters configured is the common production shape — the decorator
     * must still be wired (the condition is an OR, not an XOR).
     */
    public function completionIsFilteredWhenBothVisibilityFiltersAreConfigured(): void
    {
        $factory = new McpServerFactory(
            container: new SimpleContainer([CompletionTool::class => new CompletionTool()]),
            sessionStore: new InMemorySessionStore(),
        );

        $server = $factory->create(
            [CompletionTool::class],
            promptVisibility: new DenyPromptVisibility(['secret-review']),
            resourceVisibility: new DenyResourceVisibility(hiddenTemplates: ['app://reports/{region}']),
        );
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);

        $caught = null;

        try {
            $tester->request('completion/complete', [
                'ref' => ['type' => 'ref/prompt', 'name' => 'secret-review'],
                'argument' => ['name' => 'target', 'value' => 'in'],
            ]);
        } catch (\RuntimeException $caught) {
        }

        Assert::notNull($caught);
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

    public function reservesAttributeToolNamesForConfigurators(): void
    {
        $configurator = new ReservedNamesRecordingConfigurator();

        // CountingTool's attribute name ("count.up") differs from its method
        // name ("up"), which GreetingTool's does not — without it the
        // explicit name and the derived one are indistinguishable
        $this->factory()->create([GreetingTool::class, CountingTool::class], [$configurator]);

        Assert::same($configurator->reserved, ['greet', 'explode', 'count.up']);
        Assert::true($configurator->configured);
    }

    /**
     * The reserved names must match what the SDK's reflected loader derives,
     * or a configurator would be told the wrong names are taken: the method
     * name by default, the class short name for __invoke.
     */
    public function reservedNamesFollowTheSdkDefaultNamingRule(): void
    {
        $configurator = new ReservedNamesRecordingConfigurator();

        $this->factory()->create([DefaultNamedTool::class], [$configurator]);

        Assert::same($configurator->reserved, ['lookup', 'DefaultNamedTool']);
    }

    public function configuratorsWithoutTheInterfaceStillRun(): void
    {
        $configurator = new RecordingConfigurator();

        $this->factory()->create([GreetingTool::class], [$configurator]);

        Assert::true($configurator->configured);
    }

    public function instructionsAreServedOnlyWhenConfigured(): void
    {
        Assert::false(isset($this->tester($this->factory()->create([GreetingTool::class]))->initialize()['instructions']));

        Assert::same(
            $this->tester($this->factory(instructions: 'Read app://status first.')->create([GreetingTool::class]))->initialize()['instructions'] ?? null,
            'Read app://status first.',
        );
    }

    public function theProtocolVersionCanBePinnedAndDefaultsToTheSdkOne(): void
    {
        Assert::same(
            $this->tester($this->factory()->create([GreetingTool::class]))->initialize()['protocolVersion'] ?? null,
            MessageInterface::PROTOCOL_VERSION->value,
        );

        Assert::same(
            $this->tester($this->factory(protocolVersion: ProtocolVersion::V2025_06_18)->create([GreetingTool::class]))->initialize()['protocolVersion'] ?? null,
            '2025-06-18',
        );
    }

    public function thePaginationLimitSplitsListsIntoPages(): void
    {
        $tester = $this->tester($this->factory(paginationLimit: 1)->create([GreetingTool::class]));
        $page = $tester->request('tools/list');

        Assert::same(count((array) ($page['tools'] ?? [])), 1);
        Assert::true(isset($page['nextCursor']));
        Assert::same(count($tester->listTools()), 2);
    }

    #[DataProvider('invalidPaginationLimitProvider')]
    public function rejectsAPaginationLimitBelowOne(int $limit): void
    {
        $caught = null;

        try {
            $this->factory(paginationLimit: $limit);
        } catch (\InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('at least 1');
    }

    public static function invalidPaginationLimitProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    /**
     * The notifier and the SDK's subscribe handler must read the same
     * subscription state, so a swapped-in manager has to reach the builder.
     */
    public function aCustomSubscriptionManagerBacksTheSubscribeHandler(): void
    {
        $subscriptions = new RecordingSubscriptionManager();

        $tester = $this->tester($this->factory(subscriptionManager: $subscriptions)->create([GreetingTool::class]));
        $tester->request('resources/subscribe', ['uri' => 'app://status']);

        Assert::same($subscriptions->subscribed, ['app://status']);
    }

    /**
     * completion/complete is only decorated when a visibility filter exists;
     * with one, a hidden prompt must not complete its arguments.
     */
    public function completionIsFilteredWhenPromptVisibilityIsConfigured(): void
    {
        $factory = new McpServerFactory(
            container: new SimpleContainer([CompletionTool::class => new CompletionTool()]),
            sessionStore: new InMemorySessionStore(),
        );

        $server = $factory->create([CompletionTool::class], promptVisibility: new DenyPromptVisibility(['secret-review']));
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);

        $caught = null;

        try {
            $tester->request('completion/complete', [
                'ref' => ['type' => 'ref/prompt', 'name' => 'secret-review'],
                'argument' => ['name' => 'target', 'value' => 'in'],
            ]);
        } catch (\RuntimeException $caught) {
        }

        Assert::notNull($caught);
    }

    private function factory(
        string $instructions = '',
        int $paginationLimit = McpServerFactory::DEFAULT_PAGINATION_LIMIT,
        ?ProtocolVersion $protocolVersion = null,
        ?SubscriptionManagerInterface $subscriptionManager = null,
    ): McpServerFactory {
        return new McpServerFactory(
            container: new SimpleContainer([
                GreetingTool::class => new GreetingTool(prefix: 'Hello'),
                DisabledTool::class => new DisabledTool(enabled: false),
            ]),
            sessionStore: new InMemorySessionStore(),
            name: 'test-server',
            version: '1.0.0',
            instructions: $instructions,
            paginationLimit: $paginationLimit,
            protocolVersion: $protocolVersion,
            subscriptionManager: $subscriptionManager,
        );
    }
}
