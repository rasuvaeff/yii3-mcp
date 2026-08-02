<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\DisabledTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\ManyCapabilitiesConfigurator;
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

    /**
     * The tester's claimed protocol version and the one the server answers with
     * must be the same revision — they silently disagreed until the constant
     * started reading from the SDK, and a test client pinned to an older
     * revision than the server under test proves nothing about either.
     */
    public function testerAndServerAgreeOnTheProtocolVersion(): void
    {
        $result = $this->tester()->initialize();

        Assert::same($result['protocolVersion'], MessageInterface::PROTOCOL_VERSION->value);
    }

    public function listsToolsWithImplicitInitialize(): void
    {
        $names = array_column($this->tester()->listTools(), 'name');
        sort($names);

        Assert::same($names, ['explode', 'greet']);
    }

    public function listsEveryCapabilityAcrossPages(): void
    {
        // the default page size (50) alone would fit all 23 tools on one
        // page — a small limit forces listAll() to genuinely follow several
        // cursors, not just make a single request that happens to return
        // everything
        $tester = $this->tester(withManyCapabilities: true, paginationLimit: 5);

        Assert::same(count($tester->listTools()), 23);
        Assert::same(count($tester->listResources()), 22);
        Assert::same(count($tester->listResourceTemplates()), 22);
        Assert::same(count($tester->listPrompts()), 22);
        Assert::same(array_column($tester->listTools(), 'name')[22], 'tool-21');
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

    private function tester(bool $withDisabledTool = false, bool $withManyCapabilities = false, ?int $paginationLimit = null): McpTester
    {
        $factory = new Psr17Factory();

        return new McpTester(
            server: $this->server($withDisabledTool, $withManyCapabilities, $paginationLimit),
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    private function server(bool $withDisabledTool, bool $withManyCapabilities, ?int $paginationLimit = null): Server
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
            paginationLimit: $paginationLimit ?? McpServerFactory::DEFAULT_PAGINATION_LIMIT,
        ))->create(
            $classes,
            $withManyCapabilities ? [new ManyCapabilitiesConfigurator()] : [],
        );
    }
}
