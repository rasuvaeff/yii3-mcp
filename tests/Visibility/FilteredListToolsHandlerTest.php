<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Visibility;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyListVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Visibility\FilteredListToolsHandler;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(FilteredListToolsHandler::class)]
final class FilteredListToolsHandlerTest
{
    public function hiddenToolsDisappearFromTheListing(): void
    {
        $names = array_column(
            $this->tester(new DenyListVisibility(hidden: ['explode']))->listTools(),
            'name',
        );
        sort($names);

        Assert::same($names, ['greet']);
    }

    public function visibilityMayDependOnTheSessionClient(): void
    {
        // McpTester introduces itself as "mcp-tester" in the handshake
        $names = array_column(
            $this->tester(new DenyListVisibility(hiddenForClient: 'mcp-tester'))->listTools(),
            'name',
        );

        Assert::same($names, []);
    }

    public function everythingVisibleListsEverything(): void
    {
        $names = array_column($this->tester(new DenyListVisibility())->listTools(), 'name');
        sort($names);

        Assert::same($names, ['explode', 'greet']);
    }

    public function promptsAndResourcesAreUnaffected(): void
    {
        $tester = $this->tester(new DenyListVisibility(hidden: ['explode', 'greet']));

        Assert::same($tester->readResource('app://status')['contents'][0]['text'], 'ok');
        Assert::same(
            array_column($tester->request('prompts/list')['prompts'] ?? [], 'name'),
            ['greeting-style'],
        );
    }

    private function tester(ToolVisibilityInterface $visibility): McpTester
    {
        $factory = new Psr17Factory();

        return new McpTester(
            server: $this->server($visibility),
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    private function server(ToolVisibilityInterface $visibility): Server
    {
        return (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
            name: 'visibility-suite',
            version: '1.0.0',
        ))->create([GreetingTool::class], [], [], $visibility);
    }
}
