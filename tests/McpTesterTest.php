<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\DisabledTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(McpTester::class)]
final class McpTesterTest
{
    public function initializeReturnsServerInfo(): void
    {
        $result = $this->tester()->initialize();

        Assert::same($result['serverInfo']['name'], 'tester-suite');
    }

    public function listsToolsWithImplicitInitialize(): void
    {
        $names = array_column($this->tester()->listTools(), 'name');
        sort($names);

        Assert::same($names, ['explode', 'greet']);
    }

    public function callsToolAndReturnsResultEnvelope(): void
    {
        $result = $this->tester()->callTool('greet', ['name' => 'Yii']);

        Assert::same($result['content'][0]['text'], 'Hello, Yii!');
        Assert::false($result['isError'] ?? false);
    }

    public function readsResource(): void
    {
        $result = $this->tester()->readResource('app://status');

        Assert::same($result['contents'][0]['text'], 'ok');
    }

    public function readsTemplatedResource(): void
    {
        $result = $this->tester()->readResource('app://users/42');

        Assert::same(json_decode((string) $result['contents'][0]['text'], true), ['id' => '42']);
    }

    public function listsPrompts(): void
    {
        $prompts = $this->tester()->request('prompts/list')['prompts'] ?? [];

        Assert::same(array_column($prompts, 'name'), ['greeting-style']);
    }

    public function conditionalToolIsAbsentWhenDisabled(): void
    {
        $tester = $this->tester(withDisabledTool: true);

        $names = array_column($tester->listTools(), 'name');

        Assert::false(in_array('hidden', $names, strict: true));
    }

    public function jsonRpcErrorBecomesExceptionWithServerMessage(): void
    {
        $tester = $this->tester();
        $tester->initialize();

        $caught = null;

        try {
            $tester->request('definitely/unknown-method');
        } catch (RuntimeException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('MCP error:');
        Assert::false(str_contains($caught->getMessage(), 'unknown error'));
    }

    private function tester(bool $withDisabledTool = false): McpTester
    {
        $factory = new Psr17Factory();

        return new McpTester(
            server: $this->server($withDisabledTool),
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    private function server(bool $withDisabledTool): Server
    {
        $classes = [GreetingTool::class];

        if ($withDisabledTool) {
            $classes[] = DisabledTool::class;
        }

        return (new McpServerFactory(
            container: new SimpleContainer([
                GreetingTool::class => new GreetingTool(prefix: 'Hello'),
                DisabledTool::class => new DisabledTool(enabled: false),
            ]),
            sessionStore: new InMemorySessionStore(),
            name: 'tester-suite',
            version: '1.0.0',
        ))->create($classes);
    }
}
