<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OAuth;

use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadataHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Mcp\McpAction;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\OAuth\AuthorizedRequestHandler;
use Rasuvaeff\Yii3Mcp\OAuth\BearerTokenMiddleware;
use Rasuvaeff\Yii3Mcp\OAuth\OAuthComponentFactory;
use Rasuvaeff\Yii3Mcp\Tests\Support\ArrayCachePool;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeIssuer;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingInterceptor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

/**
 * The OAuth guard end to end with a real RS256 token: the SDK discovers the
 * issuer, fetches its keys, validates the token and the scopes, and the
 * token's identity reaches the package's interceptors as the client id.
 */
#[Test]
#[Covers(BearerTokenMiddleware::class)]
#[Covers(AuthorizedRequestHandler::class)]
#[Covers(OAuthComponentFactory::class)]
final class BearerTokenMiddlewareTest
{
    private const string RESOURCE = 'https://mcp.example.com/mcp';

    private FakeIssuer $issuer;

    private ClientInterface $http;

    private RequestFactoryInterface $requests;

    private RecordingInterceptor $interceptor;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->issuer = new FakeIssuer();
        $this->interceptor = new RecordingInterceptor();
        // the container's request factory builds the discovery and JWKS
        // requests (the SDK would otherwise discover one by itself)
        $this->requests = Understudy::for(RequestFactoryInterface::class);
        when(fn() => $this->requests->createRequest(Arg::any(), Arg::any()))
            ->answers(static fn(\Rasuvaeff\Understudy\Invocation $call): RequestInterface => new \Nyholm\Psr7\Request((string) $call->arg(0), (string) $call->arg(1)));
        $this->http = Understudy::for(ClientInterface::class);
        when(fn() => $this->http->sendRequest($this->get(FakeIssuer::DISCOVERY_URL)))
            ->returns(new Response(200, ['Content-Type' => 'application/json'], $this->issuer->discoveryDocument()));
        when(fn() => $this->http->sendRequest($this->get(FakeIssuer::JWKS_URL)))
            ->returns(new Response(200, ['Content-Type' => 'application/json'], $this->issuer->jwks()));
    }

    public function aRequestWithoutATokenIsChallengedWithTheMetadataUrl(): void
    {
        $response = $this->handle($this->guard(), $this->initialize(token: null));

        Assert::same($response->getStatusCode(), 401);
        Assert::string($response->getHeaderLine('WWW-Authenticate'))
            ->contains('resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource/mcp"');
    }

    public function aValidTokenIsServedAndItsPrincipalBecomesTheClientId(): void
    {
        $guard = $this->guard();
        $token = $this->issuer->token();
        $sessionId = $this->handle($guard, $this->initialize($token))->getHeaderLine('Mcp-Session-Id');

        $response = $this->handle($guard, $this->callGreet($token, $sessionId));

        Assert::same($response->getStatusCode(), 200);
        Assert::string((string) $response->getBody())->contains('Hello, Yii!');
        Assert::same($this->interceptor->lastContext?->clientId, serialize([FakeIssuer::ISSUER, 'agent-1', 'user-1']));
        // discovery and the key set are fetched once and then cached
        verify(fn() => $this->http->sendRequest($this->get(FakeIssuer::DISCOVERY_URL)), times: 1);
        verify(fn() => $this->http->sendRequest($this->get(FakeIssuer::JWKS_URL)), times: 1);
        verify(fn() => $this->requests->createRequest('GET', FakeIssuer::DISCOVERY_URL), times: 1);
    }

    public function theIdentityCanBeTheSubjectOrTheClient(): void
    {
        foreach ([BearerTokenMiddleware::IDENTITY_SUBJECT => 'user-1', BearerTokenMiddleware::IDENTITY_CLIENT_ID => 'agent-1'] as $identity => $expected) {
            $guard = $this->guard(['identity' => $identity]);
            $token = $this->issuer->token();
            $sessionId = $this->handle($guard, $this->initialize($token))->getHeaderLine('Mcp-Session-Id');

            $this->handle($guard, $this->callGreet($token, $sessionId));

            Assert::same($this->interceptor->lastContext?->clientId, $expected);
        }
    }

    public function aTokenWithoutTheIdentityClaimIsRefused(): void
    {
        $response = $this->handle(
            $this->guard(['identity' => BearerTokenMiddleware::IDENTITY_SUBJECT]),
            $this->initialize($this->issuer->token(['sub' => null])),
        );

        Assert::same($response->getStatusCode(), 401);
        Assert::string($response->getHeaderLine('WWW-Authenticate'))->contains('error="invalid_token"');
    }

    public function anExpiredOrForeignTokenIsRefused(): void
    {
        foreach ([
            'expired' => ['exp' => time() - 60, 'iat' => time() - 700],
            'another audience' => ['aud' => 'https://other.example.com/mcp'],
            'another issuer' => ['iss' => 'https://evil.example.com'],
        ] as $claims) {
            $response = $this->handle($this->guard(), $this->initialize($this->issuer->token($claims)));

            Assert::same($response->getStatusCode(), 401);
            Assert::string($response->getHeaderLine('WWW-Authenticate'))->contains('error="invalid_token"');
        }
    }

    public function aToolScopeIsRequiredForThatToolOnly(): void
    {
        $guard = $this->guard(['scopes' => ['tools' => ['greet' => ['mcp:write']]]]);
        $token = $this->issuer->token(['scope' => 'mcp']);
        $sessionId = $this->handle($guard, $this->initialize($token))->getHeaderLine('Mcp-Session-Id');

        $response = $this->handle($guard, $this->callGreet($token, $sessionId));

        Assert::same($response->getStatusCode(), 403);
        Assert::string($response->getHeaderLine('WWW-Authenticate'))->contains('error="insufficient_scope"');
        Assert::string($response->getHeaderLine('WWW-Authenticate'))->contains('scope="mcp:write"');
        Assert::null($this->interceptor->lastContext);
    }

    public function anImpliedScopeSatisfiesTheToolScope(): void
    {
        $guard = $this->guard(['scopes' => ['tools' => ['greet' => ['mcp:write']], 'implies' => ['mcp:admin' => ['mcp:write']]]]);
        $token = $this->issuer->token(['scope' => 'mcp:admin']);
        $sessionId = $this->handle($guard, $this->initialize($token))->getHeaderLine('Mcp-Session-Id');

        Assert::same($this->handle($guard, $this->callGreet($token, $sessionId))->getStatusCode(), 200);
    }

    public function aMethodScopeAndADefaultScopeAreEnforced(): void
    {
        $method = $this->guard(['scopes' => ['methods' => ['initialize' => ['mcp:connect']]]]);
        Assert::same($this->handle($method, $this->initialize($this->issuer->token()))->getStatusCode(), 403);
        Assert::same($this->handle($method, $this->initialize($this->issuer->token(['scope' => 'mcp:connect'])))->getStatusCode(), 200);

        $default = $this->guard(['scopes' => ['default' => ['mcp:any']]]);
        Assert::same($this->handle($default, $this->initialize($this->issuer->token()))->getStatusCode(), 403);
    }

    public function aSessionOpenedByOneTokenHolderIsMissingForAnother(): void
    {
        $guard = $this->guard();
        $sessionId = $this->handle($guard, $this->initialize($this->issuer->token()))->getHeaderLine('Mcp-Session-Id');

        $response = $this->handle($guard, $this->callGreet($this->issuer->token(['sub' => 'user-2']), $sessionId));

        Assert::same($response->getStatusCode(), 404);
        Assert::null($this->interceptor->lastContext);
    }

    public function theMetadataDocumentNamesTheResourceAndItsIssuers(): void
    {
        $handler = $this->factory(['scopes_supported' => ['mcp']])->metadataHandler();

        $response = $handler->handle(new ServerRequest('GET', 'https://mcp.example.com/.well-known/oauth-protected-resource/mcp'));

        Assert::same($response->getStatusCode(), 200);
        Assert::json((string) $response->getBody())
            ->assertPath('$.resource', static fn(mixed $json) => Assert::same($json->decode(), self::RESOURCE))
            ->assertPath('$.authorization_servers', static fn(mixed $json) => Assert::same($json->decode(), [FakeIssuer::ISSUER]))
            ->assertPath('$.scopes_supported', static fn(mixed $json) => Assert::same($json->decode(), ['mcp']));
        Assert::instanceOf($handler, ProtectedResourceMetadataHandler::class);
    }

    public function anExplicitIssuerIsUsedInsteadOfTheFirstServer(): void
    {
        $guard = $this->guard(['authorization_servers' => ['https://other.example.com', FakeIssuer::ISSUER], 'issuer' => FakeIssuer::ISSUER]);

        Assert::same($this->handle($guard, $this->initialize($this->issuer->token()))->getStatusCode(), 200);
    }

    public function everyScopeOfAToolIsRequired(): void
    {
        $guard = $this->guard(['scopes' => ['tools' => ['other' => ['x'], 'greet' => ['mcp:read', 'mcp:write']]]]);
        $partial = $this->issuer->token(['scope' => 'mcp:read']);
        $sessionId = $this->handle($guard, $this->initialize($partial))->getHeaderLine('Mcp-Session-Id');

        Assert::same($this->handle($guard, $this->callGreet($partial, $sessionId))->getStatusCode(), 403);

        $full = $this->issuer->token(['scope' => 'mcp:read mcp:write']);
        $sessionId = $this->handle($guard, $this->initialize($full))->getHeaderLine('Mcp-Session-Id');
        Assert::same($this->handle($guard, $this->callGreet($full, $sessionId))->getStatusCode(), 200);
    }

    /**
     * No clock skew is tolerated unless configured: a token expiring this
     * very second is already expired.
     */
    public function noLeewayByDefault(): void
    {
        $now = time();

        Assert::same($this->handle($this->guard(), $this->initialize($this->issuer->token(['exp' => $now, 'iat' => $now - 60])))->getStatusCode(), 401);
        Assert::same($this->handle($this->guard(['leeway' => 30]), $this->initialize($this->issuer->token(['exp' => $now, 'iat' => $now - 60])))->getStatusCode(), 200);
    }

    public function theMetadataListsEveryAuthorizationServer(): void
    {
        $servers = $this->factory(['authorization_servers' => [FakeIssuer::ISSUER, 'https://second.example.com']])->metadata()->jsonSerialize()['authorization_servers'] ?? null;

        Assert::same($servers, [FakeIssuer::ISSUER, 'https://second.example.com']);
    }

    public function anUnknownIdentityFailsAtConstruction(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Unsupported OAuth identity "email"; supported: principal, client_id, subject');

        $this->guard(['identity' => 'email']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function guard(array $overrides = []): BearerTokenMiddleware
    {
        return $this->factory($overrides)->bearerTokenMiddleware();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function factory(array $overrides = []): OAuthComponentFactory
    {
        $psr17 = new Psr17Factory();

        return new OAuthComponentFactory(
            params: [
                'resource' => self::RESOURCE,
                'authorization_servers' => [FakeIssuer::ISSUER],
                ...$overrides,
            ],
            container: new SimpleContainer([
                ResponseFactoryInterface::class => $psr17,
                StreamFactoryInterface::class => $psr17,
                RequestFactoryInterface::class => $this->requests,
                ClientInterface::class => $this->http,
                CacheItemPoolInterface::class => new ArrayCachePool(),
            ]),
        );
    }

    private function get(string $url): mixed
    {
        return Arg::satisfies(
            static fn(mixed $request): bool => $request instanceof RequestInterface && (string) $request->getUri() === $url,
            'GET ' . $url,
        );
    }

    private function handle(BearerTokenMiddleware $guard, ServerRequestInterface $request): ResponseInterface
    {
        $psr17 = new Psr17Factory();
        static $store = null;
        $store ??= new InMemorySessionStore();
        $server = (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: $store,
        ))->create([GreetingTool::class], [], [$this->interceptor]);
        $action = new McpAction(server: $server, responseFactory: $psr17, streamFactory: $psr17, sessionStore: $store);

        return $guard->process($request, new readonly class ($action) implements RequestHandlerInterface {
            public function __construct(private McpAction $action) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->action->handle($request);
            }
        });
    }

    private function initialize(?string $token): ServerRequestInterface
    {
        return $this->post($token, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'oauth-test', 'version' => '1.0'],
            ],
        ]);
    }

    private function callGreet(string $token, string $sessionId): ServerRequestInterface
    {
        return $this->post($token, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'greet', 'arguments' => ['name' => 'Yii']],
        ])
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withHeader('MCP-Protocol-Version', '2025-11-25');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(?string $token, array $payload): ServerRequestInterface
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: 'http://localhost/mcp',
            headers: ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'],
            body: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return $token === null ? $request : $request->withHeader('Authorization', 'Bearer ' . $token);
    }
}
