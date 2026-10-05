<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Capability\Registry;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Mcp\Server\Builder;
use Mcp\Server\Session\InMemorySessionStore;
use Psr\Log\NullLogger;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Mcp\Exception\DuplicateCapabilityException;
use Rasuvaeff\Yii3Mcp\GuardedRegistry;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(GuardedRegistry::class)]
#[Covers(DuplicateCapabilityException::class)]
final class GuardedRegistryTest
{
    public function duplicateToolNameThrowsInsteadOfLastWriteWins(): void
    {
        $registry = $this->registry();
        $registry->registerTool($this->tool('lookup'), static fn(): string => 'first');

        $caught = null;

        try {
            $registry->registerTool($this->tool('lookup'), static fn(): string => 'second');
        } catch (DuplicateCapabilityException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('lookup');
    }

    public function duplicateResourceUriThrows(): void
    {
        $registry = $this->registry();
        $registry->registerResource($this->resource('app://config'), static fn(): string => 'first');

        $caught = null;

        try {
            $registry->registerResource($this->resource('app://config'), static fn(): string => 'second');
        } catch (DuplicateCapabilityException $caught) {
        }

        Assert::notNull($caught);
    }

    public function duplicateResourceTemplateThrows(): void
    {
        $registry = $this->registry();
        $registry->registerResourceTemplate($this->template('app://user/{id}'), static fn(string $id): string => $id);

        $caught = null;

        try {
            $registry->registerResourceTemplate($this->template('app://user/{id}'), static fn(string $id): string => $id);
        } catch (DuplicateCapabilityException $caught) {
        }

        Assert::notNull($caught);
    }

    public function duplicatePromptNameThrows(): void
    {
        $registry = $this->registry();
        $registry->registerPrompt(new Prompt(name: 'style'), static fn(): string => 'first');

        $caught = null;

        try {
            $registry->registerPrompt(new Prompt(name: 'style'), static fn(): string => 'second');
        } catch (DuplicateCapabilityException $caught) {
        }

        Assert::notNull($caught);
    }

    public function reRegisteringAfterAnExplicitUnregisterIsAllowed(): void
    {
        $registry = $this->registry();
        $registry->registerTool($this->tool('lookup'), static fn(): string => 'first');
        $registry->unregisterTool('lookup');
        $registry->registerTool($this->tool('lookup'), static fn(): string => 'second');

        Assert::true($registry->hasTool('lookup'));
    }

    public function factoryDetectsACollisionBetweenConfiguratorAndAttributeTool(): void
    {
        // a plain configurator (NOT ReservedToolNamesAware) grabbing an
        // attribute tool's name used to silently shadow it — the SDK's
        // explicit loader runs first and its registry is last-write-wins;
        // the guarded registry turns the collision into a build error
        $factory = new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
        );

        $configurator = Understudy::for(ServerConfiguratorInterface::class);
        when(fn() => $configurator->configure(Arg::any()))->answers(
            static function (Invocation $call): void {
                $builder = $call->arg('builder');
                \assert($builder instanceof Builder);
                $builder->addTool(static fn(): string => 'shadow', name: 'greet');
            },
        );

        $caught = null;

        try {
            $factory->create([GreetingTool::class], [$configurator]);
        } catch (\Throwable $caught) {
        }

        // the SDK's loader may wrap the guard's exception; the root cause
        // must still be the duplicate detection
        Assert::notNull($caught);
        $duplicate = $caught instanceof DuplicateCapabilityException ? $caught : $caught->getPrevious();
        Assert::instanceOf($duplicate, DuplicateCapabilityException::class);
        Assert::string($caught->getMessage())->contains('greet');
    }

    public function factoryDetectsACollisionBetweenTwoConfigurators(): void
    {
        $factory = new McpServerFactory(
            container: new SimpleContainer([]),
            sessionStore: new InMemorySessionStore(),
        );

        $caught = null;

        try {
            $factory->create([], [$this->promptAddingConfigurator(), $this->promptAddingConfigurator()]);
        } catch (\Throwable $caught) {
        }

        Assert::notNull($caught);
        $duplicate = $caught instanceof DuplicateCapabilityException ? $caught : $caught->getPrevious();
        Assert::instanceOf($duplicate, DuplicateCapabilityException::class);
        Assert::string($caught->getMessage())->contains('shared-prompt');
    }

    private function promptAddingConfigurator(): ServerConfiguratorInterface
    {
        $configurator = Understudy::for(ServerConfiguratorInterface::class);
        when(fn() => $configurator->configure(Arg::any()))->answers(
            static function (Invocation $call): void {
                $builder = $call->arg('builder');
                \assert($builder instanceof Builder);
                $builder->addPrompt(static fn(): string => 'ok', name: 'shared-prompt');
            },
        );

        return $configurator;
    }

    private function registry(): GuardedRegistry
    {
        return new GuardedRegistry(new Registry(logger: new NullLogger()));
    }

    private function tool(string $name): Tool
    {
        return new Tool(
            name: $name,
            title: null,
            inputSchema: ['type' => 'object', 'properties' => new \stdClass(), 'required' => null],
            description: null,
            annotations: null,
        );
    }

    private function resource(string $uri): ResourceDefinition
    {
        return new ResourceDefinition(uri: $uri, name: 'r');
    }

    private function template(string $uriTemplate): ResourceTemplate
    {
        return new ResourceTemplate(uriTemplate: $uriTemplate, name: 't');
    }
}
