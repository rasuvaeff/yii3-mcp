<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Yii3Mcp\Exception\SessionOwnershipException;
use Rasuvaeff\Yii3Mcp\Identity\ClientIdentityContext;
use Rasuvaeff\Yii3Mcp\McpAction;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;
use Rasuvaeff\Yii3Mcp\Tests\Support\CountingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\ScriptedSessionStore;
use Rasuvaeff\Yii3Mcp\Tests\Support\StubStream;
use Rasuvaeff\Yii3Mcp\Tests\Support\WarningsAsErrors;
use Symfony\Component\Uid\Uuid;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
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
#[Covers(ClientIdentityContext::class)]
#[Covers(SessionOwnershipException::class)]
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
        $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

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

    public function scalarValueAtAnIntermediateSegmentIsTreatedAsNoOwner(): void
    {
        $sessionId = $this->initialize('client-a');
        $uuid = Uuid::fromString($sessionId);

        // the first segment resolves to a SCALAR, not an array and not
        // missing: nestedGet() must still read this as "no owner" rather
        // than attempt array-offset access into the scalar (a non-numeric
        // string offset on a string throws a TypeError in PHP 8.3+)
        $this->store->write($uuid, json_encode([
            'initialized' => true,
            'rasuvaeff' => 'unexpected-scalar-value',
        ], JSON_THROW_ON_ERROR));

        $response = $this->callTool($sessionId, clientId: 'client-b');

        Assert::same($response->getStatusCode(), 404);
        Assert::same($this->tool->calls, 0);
    }

    public function identityHolderIsDisarmedEvenWhenTheTransportThrows(): void
    {
        $request = (new ServerRequest(
            method: 'POST',
            uri: 'http://localhost/mcp',
            headers: ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'],
            body: new StubStream(content: 'x', throwOnRead: true),
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

        $delete = (new ServerRequest(method: 'DELETE', uri: 'http://localhost/mcp'))
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

        $delete = (new ServerRequest(method: 'DELETE', uri: 'http://localhost/mcp'))
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

    /**
     * Second line: without the session store the package's own guard is
     * inert, and the SDK's principal binding (0.10) still refuses a foreign
     * client — the client id is handed to it as the session's principal.
     */
    public function theSdkRefusesAForeignClientEvenWithoutTheOwnGuard(): void
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([CountingTool::class => $this->tool]),
            sessionStore: $this->store,
        ))->create([CountingTool::class]);
        $action = new McpAction(server: $server, responseFactory: $factory, streamFactory: $factory);

        $sessionId = $action->handle($this->initializeRequest('client-a'))->getHeaderLine('Mcp-Session-Id');

        Assert::same($action->handle($this->callRequest($sessionId, 'client-b'))->getStatusCode(), 404);
        Assert::same($this->tool->calls, 0);
        Assert::same($action->handle($this->callRequest($sessionId, 'client-a'))->getStatusCode(), 200);
        Assert::same($this->tool->calls, 1);
    }

    public function foreignClientIsPassedToTheSdkWhenTheSessionIsMissingFromTheStore(): void
    {
        $serverStore = new ScriptedSessionStore();
        $action = $this->actionOver(ownershipStore: $serverStore, serverStore: $serverStore);

        $response = $action->handle($this->callRequest(Uuid::v4()->toRfc4122(), clientId: 'client-b'));

        // a missing session passes the guard so the SDK answers with its own
        // regular 404: the SDK is consulted, the guard does not answer for it
        Assert::same($response->getStatusCode(), 404);
        Assert::true($serverStore->existsCalls > 0);
    }

    #[DataProvider('unusableSessionDataProvider')]
    public function sessionWithUnusableDataIsRefusedBeforeTheSdkIsConsulted(string $stored): void
    {
        $ownershipStore = new ScriptedSessionStore();
        $ownershipStore->readOverride = $stored;
        $serverStore = new ScriptedSessionStore();
        $action = $this->actionOver($ownershipStore, $serverStore);

        // refusing unusable data must not lean on PHP warnings to get there
        $response = WarningsAsErrors::around(
            fn() => $action->handle($this->callRequest(Uuid::v4()->toRfc4122(), clientId: 'client-b')),
        );

        Assert::same($response->getStatusCode(), 404);
        Assert::same($serverStore->existsCalls, 0);
        Assert::same($this->tool->calls, 0);
    }

    public static function unusableSessionDataProvider(): iterable
    {
        yield 'not json' => ['{not json'];
        yield 'json scalar' => ['5'];
        yield 'json string' => ['"client-b"'];
        yield 'json null' => ['null'];
        yield 'empty' => [''];
    }

    /**
     * Stamping only ever adds the owner to a decodable array that has none:
     * whatever the ownership store hands back, nothing is written for it.
     */
    #[DataProvider('unstampableSessionProvider')]
    public function ownerIsNotStampedIntoDataThatCannotTakeIt(string|false|null $stored): void
    {
        $ownershipStore = new ScriptedSessionStore();
        $ownershipStore->readOverride = $stored;
        $action = $this->actionOver($ownershipStore, new InMemorySessionStore());

        $response = WarningsAsErrors::around(fn() => $action->handle($this->initializeRequest('client-a')));

        // the session itself was created: stamping was reached and declined
        Assert::same($response->getStatusCode(), 200);
        Assert::true($response->getHeaderLine('Mcp-Session-Id') !== '');
        Assert::same($ownershipStore->writes, []);
    }

    public static function unstampableSessionProvider(): iterable
    {
        yield 'unreadable' => [false];
        yield 'not json' => ['{not json'];
        yield 'json scalar' => ['5'];
        yield 'json null' => ['null'];
        yield 'already owned' => [json_encode(['rasuvaeff' => ['yii3-mcp' => ['client-id' => 'client-x']]], JSON_THROW_ON_ERROR)];
    }

    public function ownerIsStampedIntoADecodableSessionWithoutAnOwner(): void
    {
        $ownershipStore = new ScriptedSessionStore();
        $ownershipStore->readOverride = '{"initialized":true}';
        $action = $this->actionOver($ownershipStore, new InMemorySessionStore());

        $action->handle($this->initializeRequest('client-a'));

        Assert::same(count($ownershipStore->writes), 1);
        Assert::same(
            json_decode($ownershipStore->writes[0], associative: true, flags: JSON_THROW_ON_ERROR),
            ['initialized' => true, 'rasuvaeff' => ['yii3-mcp' => ['client-id' => 'client-a']]],
        );
    }

    private function actionOver(SessionStoreInterface $ownershipStore, SessionStoreInterface $serverStore): McpAction
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([CountingTool::class => $this->tool]),
            sessionStore: $serverStore,
        ))->create([CountingTool::class]);

        return new McpAction(
            server: $server,
            responseFactory: $factory,
            streamFactory: $factory,
            sessionStore: $ownershipStore,
        );
    }

    private function callRequest(string $sessionId, ?string $clientId): ServerRequest
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

        return $clientId === null ? $request : $request->withAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE, $clientId);
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
            uri: 'http://localhost/mcp',
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
}
