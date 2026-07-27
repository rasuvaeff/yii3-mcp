<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Yii3Mcp\Identity\ClientIdentityContext;
use Rasuvaeff\Yii3Mcp\McpAction;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;
use Rasuvaeff\Yii3Mcp\Tests\Support\CountingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\StubStream;
use Symfony\Component\Uid\Uuid;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

/**
 * End-to-end regression for session-to-client binding: the SDK only checks
 * that a presented Mcp-Session-Id exists, so before the owner guard any
 * authenticated client could act inside — or DELETE — another client's
 * session by replaying its id.
 */
#[Test]
#[Covers(McpAction::class)]
final class SessionOwnershipTest
{
    private McpAction $action;

    private SessionStoreInterface $store;

    private CountingTool $tool;

    #[BeforeTest]
    public function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->store = new InMemorySessionStore();
        $this->tool = new CountingTool();
        $server = (new McpServerFactory(
            container: new SimpleContainer([CountingTool::class => $this->tool]),
            sessionStore: $this->store,
        ))->create([CountingTool::class]);

        $this->action = new McpAction(
            server: $server,
            responseFactory: $factory,
            streamFactory: $factory,
            sessionStore: $this->store,
        );
    }

    public function ownerIsStampedIntoTheSessionAtInitialize(): void
    {
        $sessionId = $this->initialize('client-a');

        $raw = $this->store->read(Uuid::fromString($sessionId));
        Assert::true(is_string($raw));
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $raw, associative: true, flags: JSON_THROW_ON_ERROR);

        Assert::same($data['rasuvaeff']['yii3-mcp']['client-id'] ?? null, 'client-a');
        // stamping ADDS the owner — it must never truncate the session data
        // the SDK already wrote at initialize
        Assert::true(count($data) >= 2);
    }

    public function foreignSessionRejectionUsesTheSdkErrorShapeExactly(): void
    {
        $sessionId = $this->initialize('client-a');

        $response = $this->callTool($sessionId, clientId: 'client-b');

        // byte-for-byte the SDK's own missing-session answer — any drift
        // (dropped jsonrpc key, different error code) makes foreign sessions
        // distinguishable from expired ones
        Assert::same(
            json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR),
            [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Session not found or has expired.'],
            ],
        );
    }

    public function partialNestingWithoutTheFullOwnerPathIsNotAnOwner(): void
    {
        $sessionId = $this->initialize('client-a');
        $uuid = Uuid::fromString($sessionId);

        // session data where the TOP nesting level is missing but the deeper
        // segments exist verbatim: a lookup that skips a missing segment
        // instead of failing would resolve this to "client-b" and let the
        // foreign client in
        $this->store->write($uuid, json_encode([
            'initialized' => true,
            'yii3-mcp' => ['client-id' => 'client-b'],
        ], JSON_THROW_ON_ERROR));

        $response = $this->callTool($sessionId, clientId: 'client-b');

        Assert::same($response->getStatusCode(), 404);
        Assert::same($this->tool->calls, 0);
    }

    public function identityHolderIsDisarmedEvenWhenTheTransportThrows(): void
    {
        $request = (new ServerRequest(
            method: 'POST',
            uri: '/mcp',
            headers: ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'],
            body: new StubStream(content: 'x', advertisedSize: null, throwOnRead: true),
        ))->withAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE, 'client-a');

        $caught = null;

        try {
            $this->action->handle($request);
        } catch (\LogicException $caught) {
        }

        Assert::notNull($caught);
        // the finally bracket must hold: an exploding request never leaks
        // its identity into the process slot for the next request
        Assert::null(ClientIdentityContext::current());
    }

    public function ownerKeepsUsingItsOwnSession(): void
    {
        $sessionId = $this->initialize('client-a');

        $response = $this->callTool($sessionId, clientId: 'client-a');

        Assert::same($response->getStatusCode(), 200);
        Assert::same($this->tool->calls, 1);
    }

    public function foreignClientCannotCallToolsInAnotherClientsSession(): void
    {
        $sessionId = $this->initialize('client-a');

        $response = $this->callTool($sessionId, clientId: 'client-b');

        // indistinguishable from a missing/expired session — same shape and
        // status the SDK itself answers with
        Assert::same($response->getStatusCode(), 404);
        Assert::string((string) $response->getBody())->contains('Session not found or has expired.');
        Assert::same($this->tool->calls, 0);
    }

    public function foreignClientCannotDeleteAnotherClientsSession(): void
    {
        $sessionId = $this->initialize('client-a');

        $delete = (new ServerRequest(method: 'DELETE', uri: '/mcp'))
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE, 'client-b');

        $response = $this->action->handle($delete);

        Assert::same($response->getStatusCode(), 404);
        // the session survives the foreign DELETE
        Assert::true($this->store->exists(Uuid::fromString($sessionId)));
    }

    public function ownerCanDeleteItsOwnSession(): void
    {
        $sessionId = $this->initialize('client-a');

        $delete = (new ServerRequest(method: 'DELETE', uri: '/mcp'))
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE, 'client-a');

        $response = $this->action->handle($delete);

        Assert::same($response->getStatusCode(), 200);
        Assert::false($this->store->exists(Uuid::fromString($sessionId)));
    }

    public function authenticatedClientCannotAdoptAnUnownedSession(): void
    {
        // a session created without middleware identity has no owner; an
        // authenticated client presenting its id must not claim it —
        // first-authenticated-use binding would hand a fresh session to
        // whoever replays the id first
        $sessionId = $this->initialize(clientId: null);

        $response = $this->callTool($sessionId, clientId: 'client-b');

        Assert::same($response->getStatusCode(), 404);
        Assert::same($this->tool->calls, 0);
    }

    public function deploymentsWithoutClientIdentityAreUnaffected(): void
    {
        $sessionId = $this->initialize(clientId: null);

        $response = $this->callTool($sessionId, clientId: null);

        Assert::same($response->getStatusCode(), 200);
        Assert::same($this->tool->calls, 1);
    }

    public function withoutASessionStoreTheGuardIsInert(): void
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([CountingTool::class => $this->tool]),
            sessionStore: $this->store,
        ))->create([CountingTool::class]);
        $action = new McpAction(server: $server, responseFactory: $factory, streamFactory: $factory);

        $response = $action->handle($this->initializeRequest('client-a'));

        Assert::same($response->getStatusCode(), 200);
    }

    private function initialize(?string $clientId): string
    {
        $response = $this->action->handle($this->initializeRequest($clientId));

        Assert::same($response->getStatusCode(), 200);

        return $response->getHeaderLine('Mcp-Session-Id');
    }

    private function initializeRequest(?string $clientId): ServerRequest
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

        return $clientId === null ? $request : $request->withAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE, $clientId);
    }

    private function callTool(string $sessionId, ?string $clientId): ResponseInterface
    {
        $request = $this->request(
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => ['name' => 'count.up', 'arguments' => new \stdClass()],
            ],
            sessionId: $sessionId,
        );

        if ($clientId !== null) {
            $request = $request->withAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE, $clientId);
        }

        return $this->action->handle($request);
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
}
