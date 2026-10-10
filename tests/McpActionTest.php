<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Yii3Mcp\Identity\ClientIdentityContext;
use Rasuvaeff\Yii3Mcp\McpAction;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\OutputBufferReleasingStream;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingInterceptor;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingSessionStore;
use Testo\Assert;
use Testo\Assert\Api\Json\JsonAbstract;
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

    /**
     * The widened stack must not carry the SDK's ProtocolVersionMiddleware:
     * it runs before the era is classified and refuses every 2026-07-28
     * request — with allowed_hosts set, modern clients got 400 while the same
     * server without allowed_hosts served them.
     */
    public function allowedHostsKeepServingTheModernEra(): void
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([]),
            sessionStore: new InMemorySessionStore(),
            modernEra: true,
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
            'method' => 'server/discover',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ]],
        ])
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'server/discover')
            ->withUri(new \Nyholm\Psr7\Uri('https://app.example.com/mcp'), preserveHost: false);

        $response = $action->handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::true(isset($this->decode($response)['result']['supportedVersions']));
    }

    /**
     * A subscriptions/listen stream must leave frame by frame under PHP-FPM:
     * its body ends PHP's output buffers before the SDK writes (#74). The
     * stream is not read here — it would hold the test for its lifetime.
     */
    public function eventStreamBodyReleasesOutputBuffers(): void
    {
        $factory = new Psr17Factory();
        $action = new McpAction(
            server: (new McpServerFactory(
                container: new SimpleContainer([]),
                sessionStore: new InMemorySessionStore(),
                modernEra: true,
            ))->create([]),
            responseFactory: $factory,
            streamFactory: $factory,
        );

        $response = $action->handle($this->request([
            'jsonrpc' => '2.0',
            'id' => 'listen-1',
            'method' => 'subscriptions/listen',
            'params' => [
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ],
                'notifications' => ['resourceSubscriptions' => ['app://item/1']],
            ],
        ])
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'subscriptions/listen'));

        Assert::same($response->getHeaderLine('Content-Type'), 'text/event-stream');
        Assert::instanceOf($response->getBody(), OutputBufferReleasingStream::class);
    }

    public function jsonBodyIsLeftAsIs(): void
    {
        $response = $this->initialize();

        Assert::same($response->getHeaderLine('Content-Type'), 'application/json');
        Assert::false($response->getBody() instanceof OutputBufferReleasingStream);
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

    /**
     * The stateless era has no session: an authenticated tools/call comes
     * back without an Mcp-Session-Id and leaves the store untouched — so the
     * ownership stamp (which keys on that id) has nothing to bind, and
     * identity is the request's own client id every time.
     */
    public function statelessCallCreatesNoSession(): void
    {
        $factory = new Psr17Factory();
        $store = new RecordingSessionStore();
        $server = (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: $store,
            modernEra: true,
        ))->create([GreetingTool::class]);
        $action = new McpAction(server: $server, responseFactory: $factory, streamFactory: $factory, sessionStore: $store);

        $response = $action->handle($this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'greet',
                'arguments' => ['name' => 'Yii'],
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ],
            ],
        ])
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'tools/call')
            ->withHeader('Mcp-Name', 'greet')
            ->withAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE, 'client-a'));

        Assert::same($response->getStatusCode(), 200);
        Assert::same($response->getHeaderLine('Mcp-Session-Id'), '');
        Assert::same($store->writes, 0);
        Assert::same($this->decode($response)['result']['content'][0]['text'] ?? null, 'Hello, Yii!');
    }

    public function initializeHandshakeSucceeds(): void
    {
        $response = $this->initialize();

        Assert::same($response->getStatusCode(), 200);
        Assert::true($response->getHeaderLine('Mcp-Session-Id') !== '');

        Assert::json($this->raw($response))
            ->isObject()
            ->hasKeys(['jsonrpc', 'result'])
            ->assertPath('$.jsonrpc', static function (JsonAbstract $json): void {
                Assert::same($json->decode(), '2.0');
            })
            ->assertPath('$.result.serverInfo.name', static function (JsonAbstract $json): void {
                Assert::same($json->decode(), 'test-server');
            })
            ->assertPath('$.result.serverInfo.version', static function (JsonAbstract $json): void {
                Assert::same($json->decode(), '1.0.0');
            });
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

        Assert::false(isset($this->decode($response)['error']));

        Assert::json($this->raw($response))
            ->isObject()
            ->hasKeys(['jsonrpc', 'result'])
            ->assertPath('$.jsonrpc', static function (JsonAbstract $json): void {
                Assert::same($json->decode(), '2.0');
            })
            ->assertPath('$.result.content[0].text', static function (JsonAbstract $json): void {
                Assert::same($json->decode(), 'Hello, Yii!');
            });
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

        Assert::json($this->raw($response))
            ->isObject()
            ->hasKeys(['jsonrpc', 'result'])
            ->assertPath('$.jsonrpc', static function (JsonAbstract $json): void {
                Assert::same($json->decode(), '2.0');
            })
            ->assertPath('$.result.contents[0].text', static function (JsonAbstract $json): void {
                Assert::same($json->decode(), 'ok');
            });
    }

    public function clientIdAttributeFlowsToTheInterceptorContext(): void
    {
        $factory = new Psr17Factory();
        $recording = new RecordingInterceptor();
        $server = (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
            name: 'test-server',
            version: '1.0.0',
        ))->create([GreetingTool::class], [], [$recording]);
        $action = new McpAction(server: $server, responseFactory: $factory, streamFactory: $factory);

        // Simulates SharedSecretMiddleware in front of the action.
        $withIdentity = fn(ServerRequest $request): ServerRequest => $request
            ->withAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE, 'claude');

        $sessionId = $action->handle($withIdentity($this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
            ],
        ])))->getHeaderLine('Mcp-Session-Id');

        $action->handle($withIdentity($this->request(
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => ['name' => 'greet', 'arguments' => ['name' => 'Yii']],
            ],
            sessionId: $sessionId,
        )));

        Assert::same($recording->lastContext?->clientId, 'claude');
        // The holder never outlives the request.
        Assert::null(ClientIdentityContext::current());
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
            return $request
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
        /** @var array<string, mixed> */
        return json_decode($this->raw($response), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    private function raw(ResponseInterface $response): string
    {
        $raw = (string) $response->getBody();

        // Streamable HTTP may frame the JSON-RPC message as an SSE event
        if (str_starts_with(trim($raw), 'event:') || str_starts_with(trim($raw), 'data:')) {
            preg_match('/^data: (.*)$/m', $raw, $matches);
            $raw = $matches[1] ?? '';
        }

        return $raw;
    }
}
