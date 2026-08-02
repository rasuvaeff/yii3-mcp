<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Closure;
use LogicException;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Server;
use Mcp\Server\Resource\SessionSubscriptionManager;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3Mcp\Doctor\McpDoctor;
use Rasuvaeff\Yii3Mcp\McpAction;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentity;
use Rasuvaeff\Yii3Mcp\Session\PrivateFileSessionStore;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\CountingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyListVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeCache;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeHandler;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\MutableExecutionIdentityProvider;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingConfigurator;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingInterceptor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

// Deliberately not #[CoversNothing]: this suite wires config/di.php end to
// end and is not "coverage" for any src/ class in the usual sense, but it is
// the ONLY place that constructs OpenApi\ExecutionIdentity (a bare readonly
// VO with no logic — a #[Covers] here produces zero mutants, it only makes
// the class visible to Infection instead of silently invisible, per ER-003).
#[Test]
#[Covers(ExecutionIdentity::class)]
final class ConfigWiringTest
{
    public function sessionStoreDefaultsToFpmSafePrivateFileStore(): void
    {
        /** @var array{definition: Closure} $definition */
        $definition = $this->di()[SessionStoreInterface::class];

        Assert::instanceOf($definition['definition'](), PrivateFileSessionStore::class);
    }

    public function serverDefinitionBuildsFromFactoryAndParamsTools(): void
    {
        /** @var Closure $definition */
        $definition = $this->di()[Server::class]['definition'];

        $factory = new McpServerFactory(
            container: new SimpleContainer([]),
            sessionStore: new InMemorySessionStore(),
        );

        Assert::instanceOf($definition($factory, new SimpleContainer([])), Server::class);
    }

    public function serverDefinitionRegistersConfiguredTools(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tools'] = [GreetingTool::class];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $factory = new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hi')]),
            sessionStore: new InMemorySessionStore(),
        );

        Assert::instanceOf($definition($factory, new SimpleContainer([])), Server::class);
    }

    public function budgetAndInterceptorsAreOffByDefault(): void
    {
        $params = $this->params();

        /** @var array{session: array{budget: int}, interceptors: list<class-string>} $mcp */
        $mcp = $params['rasuvaeff/yii3-mcp'];

        Assert::same($mcp['session']['budget'], 0);
        Assert::same($mcp['interceptors'], []);
        Assert::same($mcp['configurators'], []);
        Assert::same($params['rasuvaeff/yii3-mcp']['tool_visibility'], '');
        Assert::same($params['rasuvaeff/yii3-mcp']['visibility'], ['deny' => [], 'allow' => []]);
        Assert::same($params['rasuvaeff/yii3-mcp']['limits']['tool_result_bytes'], 0);
        Assert::same($params['rasuvaeff/yii3-mcp']['cache']['tools'], []);
    }

    public function serverDefinitionWiresTheSizeLimitInterceptor(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tools'] = [GreetingTool::class];
        $params['rasuvaeff/yii3-mcp']['limits']['tool_result_bytes'] = 5;

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $container = new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hi')]);
        $factory = new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore());

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);

        // "Hi, Yii!" is well over 5 bytes — the limit interceptor is
        // actually wired into the chain, not just accepted as config
        Assert::string($tester->callTool('greet', ['name' => 'Yii'])['content'][0]['text'])->contains('truncated');
    }

    public function serverDefinitionWiresTheCachingInterceptor(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tools'] = [CountingTool::class];
        $params['rasuvaeff/yii3-mcp']['cache']['tools'] = ['count.up' => 60];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $tool = new CountingTool();
        $container = new SimpleContainer([
            CountingTool::class => $tool,
            CacheInterface::class => new FakeCache(),
        ]);
        $factory = new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore());

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);

        $tester->callTool('count.up', []);
        $tester->callTool('count.up', []);

        // the second call is served from cache — the tool ran exactly once
        Assert::same($tool->calls, 1);
    }

    public function serverDefinitionPartitionsTheToolCacheByExecutionIdentity(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tools'] = [CountingTool::class];
        $params['rasuvaeff/yii3-mcp']['cache']['tools'] = ['count.up' => 60];
        $params['rasuvaeff/yii3-mcp']['openapi']['identity_provider'] = MutableExecutionIdentityProvider::class;

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $tool = new CountingTool();
        $identityProvider = new MutableExecutionIdentityProvider(new ExecutionIdentity(subjectId: 'user-1'));
        $container = new SimpleContainer([
            CountingTool::class => $tool,
            CacheInterface::class => new FakeCache(),
            MutableExecutionIdentityProvider::class => $identityProvider,
        ]);
        $factory = new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore());

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);

        $tester->callTool('count.up', []);
        $identityProvider->identity = new ExecutionIdentity(subjectId: 'user-2');
        $tester->callTool('count.up', []);

        // the configured identity provider reached the caching interceptor:
        // a different delegated identity is a different cache entry
        Assert::same($tool->calls, 2);
    }

    public function identityProviderIsNotResolvedWithoutTheBridgeOrTheToolCache(): void
    {
        // an identity provider is application code (it may read the request
        // or session), so a server that configures neither the OpenAPI
        // bridge nor the tool cache must not instantiate it
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['openapi']['identity_provider'] = MutableExecutionIdentityProvider::class;

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        // the container has no entry for the provider: resolving it would throw
        $container = new SimpleContainer([]);
        $server = $definition(new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore()), $container);

        Assert::instanceOf($server, Server::class);
    }

    public function serverDefinitionWiresToolVisibility(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tools'] = [GreetingTool::class];
        $params['rasuvaeff/yii3-mcp']['tool_visibility'] = DenyListVisibility::class;

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $container = new SimpleContainer([
            GreetingTool::class => new GreetingTool(prefix: 'Hi'),
            DenyListVisibility::class => new DenyListVisibility(hidden: ['explode']),
        ]);
        $factory = new McpServerFactory(
            container: $container,
            sessionStore: new InMemorySessionStore(),
        );

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);

        Assert::same(array_column($tester->listTools(), 'name'), ['greet']);
    }

    public function serverDefinitionWiresDeclarativeVisibility(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tools'] = [GreetingTool::class];
        $params['rasuvaeff/yii3-mcp']['visibility'] = ['deny' => ['expl*'], 'allow' => []];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $container = new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hi')]);
        $factory = new McpServerFactory(
            container: $container,
            sessionStore: new InMemorySessionStore(),
        );

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);

        Assert::same(array_column($tester->listTools(), 'name'), ['greet']);
    }

    public function serverDefinitionRejectsBothVisibilityKinds(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tool_visibility'] = DenyListVisibility::class;
        $params['rasuvaeff/yii3-mcp']['visibility'] = ['deny' => ['admin.*'], 'allow' => []];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $factory = new McpServerFactory(
            container: new SimpleContainer([]),
            sessionStore: new InMemorySessionStore(),
        );

        Expect::exception(LogicException::class);

        $definition($factory, new SimpleContainer([]));
    }

    public function serverDefinitionWiresBudgetAndConfiguredInterceptors(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tools'] = [GreetingTool::class];
        $params['rasuvaeff/yii3-mcp']['session']['budget'] = 3;
        $params['rasuvaeff/yii3-mcp']['interceptors'] = [RecordingInterceptor::class];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $recording = new RecordingInterceptor();
        $container = new SimpleContainer([
            GreetingTool::class => new GreetingTool(prefix: 'Hi'),
            RecordingInterceptor::class => $recording,
        ]);
        $factory = new McpServerFactory(
            container: $container,
            sessionStore: new InMemorySessionStore(),
        );

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);
        $tester->callTool('greet', ['name' => 'Yii']);

        // the configured interceptor actually ran → both budget guard and
        // params-listed interceptors are wired into the chain
        Assert::same($recording->entries, ['interceptor:before:greet', 'interceptor:after:greet']);
    }

    public function serverDefinitionPreservesObservableChainOrderOnCacheHit(): void
    {
        // Regression guard for the documented chain order (budget → user
        // interceptors → caching → size limit): the three isolated wiring
        // tests above would all stay green if caching were accidentally
        // moved outside the budget/user interceptors in config/di.php, since
        // each only exercises one interceptor at a time. This wires all
        // three together and asserts the OBSERVABLE contract: a cache hit
        // must still run user interceptors (RBAC/audit) and still consume
        // session budget — only the tool call itself (and its size-limiting)
        // may be skipped.
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tools'] = [CountingTool::class];
        $params['rasuvaeff/yii3-mcp']['session']['budget'] = 2;
        $params['rasuvaeff/yii3-mcp']['interceptors'] = [RecordingInterceptor::class];
        $params['rasuvaeff/yii3-mcp']['cache']['tools'] = ['count.up' => 60];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $tool = new CountingTool();
        $recording = new RecordingInterceptor();
        $container = new SimpleContainer([
            CountingTool::class => $tool,
            RecordingInterceptor::class => $recording,
            CacheInterface::class => new FakeCache(),
        ]);
        $factory = new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore());

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);

        $first = $tester->callTool('count.up', []);
        $second = $tester->callTool('count.up', []);
        // the budget is 2: if it only charged real tool executions (budget
        // wired INSIDE caching — the wrong order), this third call would
        // still succeed, since only the first call was a real execution
        $third = $tester->callTool('count.up', []);

        // the tool itself ran exactly once — the second and third calls
        // were served from cache
        Assert::same($tool->calls, 1);
        Assert::same($first['content'][0]['text'], $second['content'][0]['text']);

        // the configured (RBAC/audit) interceptor ran on both the miss AND
        // the hit — never on the third, budget-exhausted call, since budget
        // is outermost and short-circuits before reaching it
        Assert::same($recording->entries, [
            'interceptor:before:count.up', 'interceptor:after:count.up',
            'interceptor:before:count.up', 'interceptor:after:count.up',
        ]);

        // the session budget consumed on the cache hit too — a budget that
        // only charged real executions would let a client bypass it
        // entirely by hammering an already-cached tool
        Assert::string($third['content'][0]['text'])->contains('budget of 2 is exhausted');
    }

    public function serverDefinitionWiresConfiguredConfigurators(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['tools'] = [GreetingTool::class];
        $params['rasuvaeff/yii3-mcp']['configurators'] = [RecordingConfigurator::class];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $configurator = new RecordingConfigurator();
        $container = new SimpleContainer([
            GreetingTool::class => new GreetingTool(prefix: 'Hi'),
            RecordingConfigurator::class => $configurator,
        ]);
        $factory = new McpServerFactory(
            container: $container,
            sessionStore: new InMemorySessionStore(),
        );

        /** @var Server $server */
        $server = $definition($factory, $container);

        // the params-listed configurator ran against the builder before build
        Assert::true($configurator->configured);
        Assert::instanceOf($server, Server::class);
    }

    public function actionDefinitionWiresTheSessionStoreForOwnershipEnforcement(): void
    {
        /** @var array{definition: Closure} $definition */
        $definition = $this->di()[McpAction::class];

        $psr17 = new Psr17Factory();
        $action = $definition['definition'](
            (new McpServerFactory(container: new SimpleContainer([]), sessionStore: new InMemorySessionStore()))->create([]),
            $psr17,
            $psr17,
            new InMemorySessionStore(),
        );

        Assert::instanceOf($action, McpAction::class);
    }

    public function middlewareDefinitionCarriesFailClosedDefaults(): void
    {
        /** @var Closure $definition */
        $definition = $this->di()[SharedSecretMiddleware::class]['definition'];

        /** @var SharedSecretMiddleware $middleware */
        $middleware = $definition(new Psr17Factory());

        // Both secret forms empty by default: the middleware must reject
        // every request with the explanatory 503 — fail-closed is the
        // shipped default.
        $response = $middleware->process(new ServerRequest('POST', '/mcp', ['X-Mcp-Secret' => 'anything']), new FakeHandler());

        Assert::same($response->getStatusCode(), 503);
    }

    public function middlewareDefinitionBuildsAResolverFromClientSecrets(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['client_secrets'] = ['claude' => ['old-secret', 'new-secret']];

        /** @var Closure $definition */
        $definition = $this->di($params)[SharedSecretMiddleware::class]['definition'];

        Assert::instanceOf($definition(new Psr17Factory()), SharedSecretMiddleware::class);
    }

    public function middlewareDefinitionRejectsBothSecretForms(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['endpoint_secret'] = 'single';
        $params['rasuvaeff/yii3-mcp']['client_secrets'] = ['claude' => 'other'];

        /** @var Closure $definition */
        $definition = $this->di($params)[SharedSecretMiddleware::class]['definition'];

        Expect::exception(\InvalidArgumentException::class);
        $definition(new Psr17Factory());
    }

    public function appsAreOffByDefault(): void
    {
        $params = $this->params();

        Assert::same($params['rasuvaeff/yii3-mcp']['apps'], ['enable' => false, 'definitions' => []]);

        $capabilities = (array) ($this->appsTester($params)->initialize()['capabilities'] ?? []);

        Assert::false(isset($capabilities['extensions']));
    }

    public function enableAloneAdvertisesTheAppsExtension(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['apps']['enable'] = true;

        $capabilities = (array) ($this->appsTester($params)->initialize()['capabilities'] ?? []);
        $extensions = (array) ($capabilities['extensions'] ?? []);

        Assert::true(isset($extensions[McpApps::EXTENSION_ID]));
    }

    public function declarativeDefinitionsEnableTheExtensionAndAreServed(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['apps']['definitions'] = [[
            'uri' => 'ui://dashboard',
            'name' => 'dashboard',
            'html' => '<h1>Sales</h1>',
            'csp' => ['connect_domains' => ['api.example.com']],
            'permissions' => ['camera' => false, 'geolocation' => true],
        ]];

        $tester = $this->appsTester($params);
        $extensions = (array) (((array) ($tester->initialize()['capabilities'] ?? []))['extensions'] ?? []);

        Assert::true(isset($extensions[McpApps::EXTENSION_ID]));
        Assert::same(array_column($tester->listResources(), 'uri'), ['ui://dashboard']);

        $content = ((array) ($tester->readResource('ui://dashboard')['contents'] ?? []))[0] ?? [];
        $content = is_array($content) ? $content : [];

        Assert::same($content['text'] ?? null, '<h1>Sales</h1>');
        // `'camera' => false` must NOT become a requested permission
        Assert::same($content['_meta'] ?? null, [
            'ui' => [
                'csp' => ['connectDomains' => ['api.example.com']],
                'permissions' => ['geolocation' => []],
            ],
        ]);
    }

    public function instructionsAreServedInInitializeWhenConfigured(): void
    {
        $params = $this->params();

        Assert::same($params['rasuvaeff/yii3-mcp']['instructions'], '');
        Assert::false(isset($this->serverTester($params)->initialize()['instructions']));

        $params['rasuvaeff/yii3-mcp']['instructions'] = 'Call order.status before cancelling.';

        Assert::same(
            $this->serverTester($params)->initialize()['instructions'] ?? null,
            'Call order.status before cancelling.',
        );
    }

    /**
     * The SDK's own list handlers and this package's filtering ones must page
     * identically — a limit applied to only one of them silently changes what
     * a client sees depending on whether visibility is configured.
     */
    public function paginationLimitAppliesToPlainAndFilteredListsAlike(): void
    {
        $params = $this->params();

        Assert::same($params['rasuvaeff/yii3-mcp']['pagination_limit'], 50);

        // GreetingTool declares two tools; a limit of 1 must split them
        $params['rasuvaeff/yii3-mcp']['pagination_limit'] = 1;
        $params['rasuvaeff/yii3-mcp']['tools'] = [GreetingTool::class];

        $tester = $this->serverTester($params, withGreeting: true);
        $page = $tester->request('tools/list');

        Assert::same(count((array) ($page['tools'] ?? [])), 1);
        Assert::true(isset($page['nextCursor']));
        // McpTester follows the cursors, so the full set is still reachable
        Assert::same(count($tester->listTools()), 2);
    }

    public function protocolVersionIsTheSdkDefaultUnlessPinned(): void
    {
        $params = $this->params();

        Assert::same($params['rasuvaeff/yii3-mcp']['protocol_version'], '');
        Assert::same(
            $this->serverTester($params)->initialize()['protocolVersion'] ?? null,
            MessageInterface::PROTOCOL_VERSION->value,
        );

        $params['rasuvaeff/yii3-mcp']['protocol_version'] = '2025-06-18';

        Assert::same($this->serverTester($params)->initialize()['protocolVersion'] ?? null, '2025-06-18');
    }

    public function anUnsupportedProtocolVersionFailsAtConfigLoad(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['protocol_version'] = '1999-01-01';

        $caught = null;

        try {
            $this->di($params);
        } catch (\InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())
            ->contains('1999-01-01')
            ->contains('2025-11-25');
    }

    public function theSubscriptionManagerIsBoundSoBothSidesShareTheState(): void
    {
        Assert::same($this->di()[SubscriptionManagerInterface::class], SessionSubscriptionManager::class);
    }

    public function doctorDefinitionBuildsFromParamsAndContainer(): void
    {
        /** @var Closure $definition */
        $definition = $this->di()[McpDoctor::class]['definition'];

        $doctor = $definition(new SimpleContainer([
            SessionStoreInterface::class => new InMemorySessionStore(),
        ]));

        Assert::instanceOf($doctor, McpDoctor::class);
    }

    public function doctorDefinitionResolvesTheDefaultSessionDirectory(): void
    {
        /** @var Closure $definition */
        $definition = $this->di()[McpDoctor::class]['definition'];

        /** @var McpDoctor $doctor */
        $doctor = $definition(new SimpleContainer([
            SessionStoreInterface::class => new InMemorySessionStore(),
        ]));

        // The empty params default resolves to the same directory the
        // SessionStoreInterface definition uses — the doctor must diagnose
        // the real store location, not a different one.
        $report = $doctor->diagnose();
        $checks = $report->toArray()['checks'];
        $sessionDirectory = array_values(array_filter(
            $checks,
            static fn(array $check): bool => $check['name'] === 'session_directory',
        ));
        Assert::string($sessionDirectory[0]['details'])->contains('yii3-mcp-sessions');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function serverTester(array $params, bool $withGreeting = false): McpTester
    {
        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $container = new SimpleContainer($withGreeting ? [GreetingTool::class => new GreetingTool(prefix: 'Hi')] : []);
        /** @var array{instructions?: string, pagination_limit?: int} $mcp */
        $mcp = $params['rasuvaeff/yii3-mcp'];
        $factory = new McpServerFactory(
            container: $container,
            sessionStore: new InMemorySessionStore(),
            instructions: $mcp['instructions'] ?? '',
            paginationLimit: $mcp['pagination_limit'] ?? McpServerFactory::DEFAULT_PAGINATION_LIMIT,
            protocolVersion: $this->pinnedProtocolVersion($params),
        );

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();

        return new McpTester($server, $psr17, $psr17, $psr17);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function pinnedProtocolVersion(array $params): ?ProtocolVersion
    {
        /** @var array{protocol_version?: string} $mcp */
        $mcp = $params['rasuvaeff/yii3-mcp'];
        $pinned = $mcp['protocol_version'] ?? '';

        return $pinned === '' ? null : ProtocolVersion::from($pinned);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function appsTester(array $params): McpTester
    {
        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $container = new SimpleContainer([]);
        $factory = new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore());

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();

        return new McpTester($server, $psr17, $psr17, $psr17);
    }

    /**
     * @return array<string, mixed>
     */
    private function params(): array
    {
        return require dirname(__DIR__) . '/config/params.php';
    }

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    private function di(?array $params = null): array
    {
        $params ??= $this->params();

        return (static fn(array $params): array => require dirname(__DIR__) . '/config/di.php')($params);
    }
}
