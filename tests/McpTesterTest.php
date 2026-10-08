<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpErrorException;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\DisabledTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\ElicitingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\HeaderParamToolConfigurator;
use Rasuvaeff\Yii3Mcp\Tests\Support\ManyCapabilitiesConfigurator;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(McpTester::class)]
#[Covers(McpErrorException::class)]
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

        Assert::same(json_decode((string) $result['contents'][0]['text'], associative: true), ['id' => '42']);
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

    public function jsonRpcErrorCarriesTheWholeEnvelope(): void
    {
        $tester = $this->tester();

        try {
            $tester->callTool('no-such-tool');
            $caught = null;
        } catch (McpErrorException $caught) {
        }

        Assert::instanceOf($caught, McpErrorException::class);
        Assert::same($caught->errorCode, -32602);
        Assert::same($caught->errorMessage, 'Tool not found: "no-such-tool".');
        Assert::same($caught->getMessage(), 'MCP error: Tool not found: "no-such-tool".');
    }

    /**
     * The modern era has no initialize: every request stands alone, so the
     * tester sends no session id and the server hands none back.
     */
    public function modernEraCallsAToolWithoutAHandshake(): void
    {
        $result = $this->modern()->callTool('greet', ['name' => 'Yii']);

        Assert::same($result['content'][0]['text'], 'Hello, Yii!');
    }

    public function modernEraInitializeAsksServerDiscover(): void
    {
        $result = $this->modern()->initialize();

        Assert::true(in_array('2026-07-28', $result['supportedVersions'] ?? [], strict: true));
        Assert::true(isset($result['capabilities']['tools']));
    }

    public function modernEraListsEveryCapabilityAcrossPages(): void
    {
        $tester = $this->modern(withManyCapabilities: true, paginationLimit: 5);

        Assert::same(count($tester->listTools()), 23);
        Assert::same(count($tester->listResources()), 22);
        Assert::same(count($tester->listResourceTemplates()), 22);
        Assert::same(count($tester->listPrompts()), 22);
    }

    public function modernEraReadsStaticAndTemplatedResources(): void
    {
        $tester = $this->modern();

        Assert::same($tester->readResource('app://status')['contents'][0]['text'] ?? null, 'ok');
        Assert::string($tester->readResource('app://users/7')['contents'][0]['text'] ?? '')->contains('7');
    }

    public function modernEraErrorCarriesTheWholeEnvelope(): void
    {
        try {
            $this->modern()->callTool('no-such-tool');
            $caught = null;
        } catch (McpErrorException $caught) {
        }

        Assert::instanceOf($caught, McpErrorException::class);
        Assert::same($caught->errorCode, -32602);
    }

    /**
     * A server with the modern era switched off answers a 2026-07-28 client
     * with "unsupported protocol version" — such a client does not fall back
     * to initialize on its own.
     */
    public function modernClientIsRefusedWhenTheServerDisablesTheEra(): void
    {
        try {
            $this->tester(protocolVersion: ProtocolVersion::V2026_07_28)->callTool('greet', ['name' => 'Yii']);
            $caught = null;
        } catch (McpErrorException $caught) {
        }

        Assert::instanceOf($caught, McpErrorException::class);
        Assert::same($caught->errorMessage, 'Unsupported protocol version');
        Assert::same($caught->errorData['requested'] ?? null, '2026-07-28');
        Assert::false(in_array('2026-07-28', $caught->errorData['supported'] ?? [], strict: true));
    }

    /**
     * An explicit handshake revision is negotiated through initialize like
     * the default one.
     */
    public function olderHandshakeRevisionIsNegotiated(): void
    {
        $result = $this->tester(protocolVersion: ProtocolVersion::V2025_06_18)->initialize();

        Assert::same($result['protocolVersion'], '2025-06-18');
    }

    /**
     * A tool argument annotated x-mcp-header must be mirrored as an
     * Mcp-Param-* header; the tester learns which from tools/list, exactly as
     * a conformant client does — and without it the server refuses the call.
     */
    public function modernEraMirrorsDeclaredHeaderParamsAfterListing(): void
    {
        $listed = $this->headerParamTester();
        $listed->listTools();

        Assert::same($listed->callTool('report.by-region', ['region' => 'emea'])['content'][0]['text'] ?? null, 'report for emea');

        try {
            $this->headerParamTester()->callTool('report.by-region', ['region' => 'emea']);
            $caught = null;
        } catch (McpErrorException $caught) {
        }

        Assert::instanceOf($caught, McpErrorException::class);
    }

    /**
     * The declared capabilities reach the server on both eras — what a tool
     * checks before asking the user.
     */
    public function declaredCapabilitiesReachTheServer(): void
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([ElicitingTool::class => new ElicitingTool()]),
            sessionStore: new InMemorySessionStore(),
        ))->create([ElicitingTool::class]);
        $ask = new ClientCapabilities(elicitation: true);

        foreach ([null, ProtocolVersion::V2026_07_28] as $version) {
            $declaring = new McpTester($server, $factory, $factory, $factory, $version, $ask);
            $silent = new McpTester($server, $factory, $factory, $factory, $version);

            Assert::same($declaring->callTool('client.can-ask')['content'][0]['text'] ?? null, 'yes');
            Assert::same($silent->callTool('client.can-ask')['content'][0]['text'] ?? null, 'no');
        }
    }

    private function headerParamTester(): McpTester
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([]),
            sessionStore: new InMemorySessionStore(),
        ))->create([], [new HeaderParamToolConfigurator()]);

        return new McpTester($server, $factory, $factory, $factory, ProtocolVersion::V2026_07_28);
    }

    private function tester(
        bool $withDisabledTool = false,
        bool $withManyCapabilities = false,
        ?int $paginationLimit = null,
        ?ProtocolVersion $protocolVersion = null,
        bool $modernEra = false,
    ): McpTester {
        $factory = new Psr17Factory();

        return new McpTester(
            server: $this->server($withDisabledTool, $withManyCapabilities, $paginationLimit, $modernEra),
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
            protocolVersion: $protocolVersion,
        );
    }

    private function modern(bool $withManyCapabilities = false, ?int $paginationLimit = null): McpTester
    {
        return $this->tester(
            withManyCapabilities: $withManyCapabilities,
            paginationLimit: $paginationLimit,
            protocolVersion: ProtocolVersion::V2026_07_28,
            modernEra: true,
        );
    }

    private function server(bool $withDisabledTool, bool $withManyCapabilities, ?int $paginationLimit = null, bool $modernEra = false): Server
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
            modernEra: $modernEra,
        ))->create(
            $classes,
            $withManyCapabilities ? [new ManyCapabilitiesConfigurator()] : [],
        );
    }
}
