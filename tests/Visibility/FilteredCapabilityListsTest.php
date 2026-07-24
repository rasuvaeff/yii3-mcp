<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Visibility;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyListVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyPromptVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyResourceVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\OnlyPromptTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\OnlyResourceTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\OnlyTemplateTool;
use Rasuvaeff\Yii3Mcp\Visibility\FilteredListPromptsHandler;
use Rasuvaeff\Yii3Mcp\Visibility\FilteredListResourcesHandler;
use Rasuvaeff\Yii3Mcp\Visibility\FilteredListResourceTemplatesHandler;
use Rasuvaeff\Yii3Mcp\Visibility\PromptVisibilityInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ResourceVisibilityInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(FilteredListPromptsHandler::class)]
#[Covers(FilteredListResourcesHandler::class)]
#[Covers(FilteredListResourceTemplatesHandler::class)]
#[Covers(McpServerFactory::class)]
final class FilteredCapabilityListsTest
{
    public function hiddenPromptsDisappearFromTheListing(): void
    {
        $names = array_column(
            $this->tester(promptVisibility: new DenyPromptVisibility(hidden: ['greeting-style']))->listPrompts(),
            'name',
        );
        sort($names);

        Assert::same($names, ['only-prompt']);
    }

    public function everyPromptVisibleListsEverything(): void
    {
        $names = array_column(
            $this->tester(promptVisibility: new DenyPromptVisibility())->listPrompts(),
            'name',
        );
        sort($names);

        Assert::same($names, ['greeting-style', 'only-prompt']);
    }

    public function hiddenResourcesDisappearFromTheListing(): void
    {
        $uris = array_column(
            $this->tester(resourceVisibility: new DenyResourceVisibility(hiddenUris: ['app://status']))->listResources(),
            'uri',
        );
        sort($uris);

        Assert::same($uris, ['app://only-resource']);
    }

    public function hiddenTemplatesDisappearFromTheListing(): void
    {
        $templates = array_column(
            $this->tester(resourceVisibility: new DenyResourceVisibility(hiddenTemplates: ['app://users/{id}']))
                ->listResourceTemplates(),
            'uriTemplate',
        );
        sort($templates);

        Assert::same($templates, ['app://items/{id}']);
    }

    public function allThreeVisibilityAxesFilterTogether(): void
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
            name: 'filtered-lists-suite',
            version: '1.0.0',
        ))->create(
            [GreetingTool::class],
            toolVisibility: new DenyListVisibility(hidden: ['explode']),
            promptVisibility: new DenyPromptVisibility(hidden: ['greeting-style']),
            resourceVisibility: new DenyResourceVisibility(hiddenUris: ['app://status']),
        );
        $tester = new McpTester($server, $factory, $factory, $factory);

        Assert::same(array_column($tester->listTools(), 'name'), ['greet']);
        Assert::same($tester->listPrompts(), []);
        Assert::same($tester->listResources(), []);
    }

    public function resourceVisibilityLeavesPromptsUntouched(): void
    {
        $names = array_column(
            $this->tester(resourceVisibility: new DenyResourceVisibility(hiddenUris: ['app://status']))->listPrompts(),
            'name',
        );
        sort($names);

        Assert::same($names, ['greeting-style', 'only-prompt']);
    }

    private function tester(
        ?PromptVisibilityInterface $promptVisibility = null,
        ?ResourceVisibilityInterface $resourceVisibility = null,
    ): McpTester {
        $factory = new Psr17Factory();

        return new McpTester(
            server: $this->server($promptVisibility, $resourceVisibility),
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    private function server(
        ?PromptVisibilityInterface $promptVisibility,
        ?ResourceVisibilityInterface $resourceVisibility,
    ): Server {
        return (new McpServerFactory(
            container: new SimpleContainer([
                GreetingTool::class => new GreetingTool(prefix: 'Hello'),
                OnlyPromptTool::class => new OnlyPromptTool(),
                OnlyResourceTool::class => new OnlyResourceTool(),
                OnlyTemplateTool::class => new OnlyTemplateTool(),
            ]),
            sessionStore: new InMemorySessionStore(),
            name: 'filtered-lists-suite',
            version: '1.0.0',
        ))->create(
            [GreetingTool::class, OnlyPromptTool::class, OnlyResourceTool::class, OnlyTemplateTool::class],
            promptVisibility: $promptVisibility,
            resourceVisibility: $resourceVisibility,
        );
    }
}
