<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Yii3Mcp\McpAction;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(McpAction::class)]
final class McpActionTest
{
    private McpAction $action;

    #[BeforeTest]
    public function setUp(): void
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([
                GreetingTool::class => new GreetingTool(prefix: 'Hello'),
            ]),
            sessionStore: new InMemorySessionStore(),
            name: 'test-server',
            version: '1.0.0',
        ))->create([GreetingTool::class]);

        $this->action = new McpAction(
            server: $server,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    public function rewindsBodyConsumedByUpstreamMiddleware(): void
    {
        $request = $this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
            ],
        ]);
        $request->getBody()->getContents(); // simulate a body parser upstream

        $response = $this->action->handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::true($response->getHeaderLine('Mcp-Session-Id') !== '');
    }

    public function customHostIsRejectedByDefault(): void
    {
        $request = $this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
            ],
        ])->withUri(new \Nyholm\Psr7\Uri('https://app.example.com/mcp'), preserveHost: false);

        Assert::same($this->action->handle($request)->getStatusCode(), 403);
    }

    public function allowedHostsWidenDnsRebindingProtection(): void
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([]),
            sessionStore: new InMemorySessionStore(),
        ))->create([]);

        $action = new McpAction(
            server: $server,
            responseFactory: $factory,
            streamFactory: $factory,
            allowedHosts: ['app.example.com'],
        );

        $request = $this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
            ],
        ])->withUri(new \Nyholm\Psr7\Uri('https://app.example.com/mcp'), preserveHost: false);

        Assert::same($action->handle($request)->getStatusCode(), 200);
    }

    public function localHostsStayAllowedWhenCustomHostsAreSet(): void
    {
        foreach (['localhost', '127.0.0.1', '[::1]'] as $localHost) {
            $response = $this->actionWithHosts(['app.example.com'])->handle($this->initializeRequestFor($localHost));

            Assert::same($response->getStatusCode(), 200);
        }
    }

    public function corsHeadersSurviveTheWidenedMiddlewareStack(): void
    {
        $response = $this->actionWithHosts(['app.example.com'])->handle(
            $this->initializeRequestFor('app.example.com')->withHeader('Origin', 'https://client.example.com'),
        );

        Assert::true($response->getHeaderLine('Access-Control-Expose-Headers') !== '');
    }

    public function everyConfiguredHostIsAllowed(): void
    {
        $action = $this->actionWithHosts(['a.example.com', 'b.example.com']);

        foreach (['a.example.com', 'b.example.com'] as $host) {
            Assert::same($action->handle($this->initializeRequestFor($host))->getStatusCode(), 200);
        }
    }

    private function actionWithHosts(array $allowedHosts): McpAction
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([]),
            sessionStore: new InMemorySessionStore(),
        ))->create([]);

        return new McpAction(
            server: $server,
            responseFactory: $factory,
            streamFactory: $factory,
            allowedHosts: $allowedHosts,
        );
    }

    private function initializeRequestFor(string $host): ServerRequest
    {
        return $this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
            ],
        ])->withUri(new \Nyholm\Psr7\Uri('https://' . $host . '/mcp'), preserveHost: false);
    }

    public function initializeHandshakeSucceeds(): void
    {
        $response = $this->initialize();

        Assert::same($response->getStatusCode(), 200);
        Assert::true($response->getHeaderLine('Mcp-Session-Id') !== '');

        $body = $this->decode($response);
        Assert::same($body['result']['serverInfo']['name'], 'test-server');
        Assert::same($body['result']['serverInfo']['version'], '1.0.0');
    }

    public function toolsListExposesRegisteredTool(): void
    {
        $sessionId = $this->initialize()->getHeaderLine('Mcp-Session-Id');

        $response = $this->action->handle($this->request(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            sessionId: $sessionId,
        ));

        $names = array_column($this->decode($response)['result']['tools'], 'name');
        sort($names);

        Assert::same($names, ['explode', 'greet']);
    }

    public function toolsCallInvokesToolResolvedThroughContainer(): void
    {
        $sessionId = $this->initialize()->getHeaderLine('Mcp-Session-Id');

        $response = $this->action->handle($this->request(
            [
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => ['name' => 'greet', 'arguments' => ['name' => 'Yii']],
            ],
            sessionId: $sessionId,
        ));

        $body = $this->decode($response);

        Assert::false(isset($body['error']));
        Assert::same($body['result']['content'][0]['text'], 'Hello, Yii!');
    }

    public function throwingToolProducesMcpErrorWithoutLeakingInternals(): void
    {
        $sessionId = $this->initialize()->getHeaderLine('Mcp-Session-Id');

        $response = $this->action->handle($this->request(
            [
                'jsonrpc' => '2.0',
                'id' => 4,
                'method' => 'tools/call',
                'params' => ['name' => 'explode', 'arguments' => []],
            ],
            sessionId: $sessionId,
        ));

        Assert::same($response->getStatusCode(), 200);

        $body = $this->decode($response);
        $isToolError = ($body['result']['isError'] ?? false) === true || isset($body['error']);
        Assert::true($isToolError);
    }

    public function resourceIsReadable(): void
    {
        $sessionId = $this->initialize()->getHeaderLine('Mcp-Session-Id');

        $response = $this->action->handle($this->request(
            [
                'jsonrpc' => '2.0',
                'id' => 5,
                'method' => 'resources/read',
                'params' => ['uri' => 'app://status'],
            ],
            sessionId: $sessionId,
        ));

        Assert::same($this->decode($response)['result']['contents'][0]['text'], 'ok');
    }

    private function initialize(): ResponseInterface
    {
        return $this->action->handle($this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
            ],
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(array $payload, string $sessionId = ''): ServerRequest
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/mcp',
            headers: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
            ],
            body: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        if ($sessionId !== '') {
            $request = $request
                ->withHeader('Mcp-Session-Id', $sessionId)
                ->withHeader('MCP-Protocol-Version', '2025-06-18');
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();

        // Streamable HTTP may frame the JSON-RPC message as an SSE event
        if (str_starts_with(trim($raw), 'event:') || str_starts_with(trim($raw), 'data:')) {
            preg_match('/^data: (.*)$/m', $raw, $matches);
            $raw = $matches[1] ?? '';
        }

        /** @var array<string, mixed> */
        return json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
    }
}
