<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Closure;
use LogicException;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Resource\SessionSubscriptionManager;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Mcp\Doctor\McpDoctor;
use Rasuvaeff\Yii3Mcp\McpAction;
use Rasuvaeff\Yii3Mcp\McpDoctorCommand;
use Rasuvaeff\Yii3Mcp\McpListCommand;
use Rasuvaeff\Yii3Mcp\McpServeCommand;
use Rasuvaeff\Yii3Mcp\McpServerComponentResolver;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\UnsafeOperationException;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentity;
use Rasuvaeff\Yii3Mcp\OpenApi\Operation;
use Rasuvaeff\Yii3Mcp\Session\PrivateFileSessionStore;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\CallbackOperationModifier;
use Rasuvaeff\Yii3Mcp\Tests\Support\CountingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyListVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeCache;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeHttpClient;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\IdentityDelegatedHeaderProvider;
use Rasuvaeff\Yii3Mcp\Tests\Support\MutableExecutionIdentityProvider;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingConfigurator;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingInterceptor;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingRequestHandler;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingScope;
use Rasuvaeff\Yii3Mcp\Tests\Support\StructuredWeatherTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\SubjectRequestAttributes;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

// Deliberately not #[CoversNothing]: this suite wires config/di.php end to
// end and exercises every branch of McpServerComponentResolver. It is also
// the only place that constructs OpenApi\ExecutionIdentity, a bare readonly
// VO that still has to remain visible to Infection per ER-003.
#[Test]
#[Covers(ExecutionIdentity::class)]
#[Covers(McpServerComponentResolver::class)]
#[Covers(\Rasuvaeff\Yii3Mcp\OpenApi\OpenApiBridgeFactory::class)]
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
        Assert::same($params['rasuvaeff/yii3-mcp']['result_json'], 'pretty');
        Assert::same($params['rasuvaeff/yii3-mcp']['openapi']['executor'], 'http');
    }

    /**
     * The commands the README documents must be a `yii list` away after
     * `composer require` — the package's params contribution is what makes
     * that true, so it is asserted as a contract, not prose.
     */
    public function consoleCommandsAreRegisteredThroughYiiConsoleParams(): void
    {
        $params = $this->params();

        Assert::same($params['yiisoft/yii-console']['commands'], [
            'mcp:serve' => McpServeCommand::class,
            'mcp:list' => McpListCommand::class,
            'mcp:doctor' => McpDoctorCommand::class,
        ]);
    }

    public function resultJsonDefaultsToPrettyPrintedText(): void
    {
        $params = $this->params();

        Assert::same($params['rasuvaeff/yii3-mcp']['result_json'], 'pretty');

        // the default must stay byte-identical to the SDK's own formatting —
        // pretty text with structuredContent for an array result
        $result = $this->weatherResult($params);

        Assert::string($result['content'][0]['text'])->contains("\n");
        Assert::same($result['structuredContent'] ?? null, ['city' => 'Rome', 'temperature' => 21, 'conditions' => 'sunny']);
    }

    public function compactResultJsonEncodesArrayToolsWithoutIndentation(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['result_json'] = 'compact';

        $result = $this->weatherResult($params);

        Assert::same($result['content'][0]['text'], '{"city":"Rome","temperature":21,"conditions":"sunny"}');
        Assert::same($result['structuredContent'] ?? null, ['city' => 'Rome', 'temperature' => 21, 'conditions' => 'sunny']);
    }

    public function compactResultJsonLeavesStringResultsUnchanged(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['result_json'] = 'compact';
        $params['rasuvaeff/yii3-mcp']['tools'] = [GreetingTool::class];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $container = new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hi')]);
        $factory = new McpServerFactory(
            container: $container,
            sessionStore: new InMemorySessionStore(),
            compactToolResults: true,
        );

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();
        $result = (new McpTester($server, $psr17, $psr17, $psr17))->callTool('greet', ['name' => 'Yii']);

        Assert::same($result['content'][0]['text'], 'Hi, Yii!');
    }

    public function compactResultJsonReachesTheFactoryWiring(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['result_json'] = 'compact';

        /** @var array{__construct(): array<string, mixed>} $factory */
        $factory = $this->di($params)[McpServerFactory::class];

        Assert::true($factory['__construct()']['compactToolResults']);
    }

    public function anInvalidResultJsonValueFailsAtConfigLoad(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['result_json'] = 'monospace';

        $caught = null;

        try {
            $this->di($params);
        } catch (\InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())
            ->contains('monospace')
            ->contains('pretty')
            ->contains('compact');
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

    public function serverDefinitionIsolatesTheToolCacheByConfiguredNamespace(): void
    {
        $tool = new CountingTool();
        $container = new SimpleContainer([
            CountingTool::class => $tool,
            CacheInterface::class => new FakeCache(),
        ]);
        $psr17 = new Psr17Factory();

        foreach (['alpha', 'beta'] as $namespace) {
            $params = $this->params();
            $params['rasuvaeff/yii3-mcp']['tools'] = [CountingTool::class];
            $params['rasuvaeff/yii3-mcp']['cache']['tools'] = ['count.up' => 60];
            $params['rasuvaeff/yii3-mcp']['cache']['namespace'] = $namespace;

            /** @var Closure $definition */
            $definition = $this->di($params)[Server::class]['definition'];
            $factory = new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore());

            /** @var Server $server */
            $server = $definition($factory, $container);
            (new McpTester($server, $psr17, $psr17, $psr17))->callTool('count.up', []);
        }

        // two servers, one cache backend: the configured namespace reaches the
        // interceptor, so "beta" never reads what "alpha" wrote
        Assert::same($tool->calls, 2);
    }

    public function serverDefinitionWiresTheConfiguredPromptResultLimit(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['prompts_path'] = __DIR__ . '/Support/prompts-amplify';
        $params['rasuvaeff/yii3-mcp']['limits']['prompt_result_bytes'] = 200;

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];
        $container = new SimpleContainer([]);

        /** @var Server $server */
        $server = $definition(
            new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore()),
            $container,
        );
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);

        // ten {{payload}} occurrences amplify 50 bytes to 510 — over the
        // configured 200-byte budget, well under the 1 MiB default
        Expect::exception(RuntimeException::class);

        $tester->request('prompts/get', ['name' => 'amplify', 'arguments' => ['payload' => str_repeat('A', 50)]]);
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

    public function identityProviderIsNotResolvedForIncompleteOpenApiConfiguration(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['openapi']['identity_provider'] = MutableExecutionIdentityProvider::class;
        $params['rasuvaeff/yii3-mcp']['openapi']['spec_path'] = '/does/not/exist.yaml';
        $params['rasuvaeff/yii3-mcp']['openapi']['operations'] = [];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];
        $container = new SimpleContainer([]);

        Assert::instanceOf(
            $definition(new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore()), $container),
            Server::class,
        );
    }

    public function operationsWithoutAnOpenApiSpecDoNotBuildTheBridge(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['openapi']['identity_provider'] = MutableExecutionIdentityProvider::class;
        $params['rasuvaeff/yii3-mcp']['openapi']['spec_path'] = '';
        $params['rasuvaeff/yii3-mcp']['openapi']['operations'] = ['getGreeting'];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];
        $container = new SimpleContainer([]);

        Assert::instanceOf(
            $definition(new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore()), $container),
            Server::class,
        );
    }

    /**
     * The unit tests construct the executor directly, so they cannot see a
     * typo in the params key or in the resolver's argument name — `?? []`
     * would swallow either silently and every path argument would quietly
     * stay single-segment.
     */
    public function multiSegmentPathParamsReachTheBridgedExecutor(): void
    {
        $client = new FakeHttpClient();
        $psr17 = new Psr17Factory();
        $path = sys_get_temp_dir() . '/yii3-mcp-wiring-spec-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));

        $params = $this->params();
        $mcp = &$params['rasuvaeff/yii3-mcp'];
        $mcp['tools'] = [];
        $mcp['openapi']['spec_path'] = $path;
        $mcp['openapi']['base_url'] = 'https://api.test/';
        $mcp['openapi']['operations'] = ['getBlogTagBySlug'];
        $mcp['openapi']['multi_segment_path_params'] = ['slug' => 3];

        try {
            /** @var Closure $definition */
            $definition = $this->di($params)[Server::class]['definition'];
            $container = new SimpleContainer([
                ClientInterface::class => $client,
                RequestFactoryInterface::class => $psr17,
                StreamFactoryInterface::class => $psr17,
            ]);
            $factory = new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore());

            /** @var Server $server */
            $server = $definition($factory, $container);

            (new McpTester($server, $psr17, $psr17, $psr17))
                ->callTool('getBlogTagBySlug', ['slug' => 'dev/keppio']);
        } finally {
            @unlink($path);
        }

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/rest/blog-tag/dev%2Fkeppio');
    }

    /**
     * Every optional `openapi` key the resolver reads with `??` is set here
     * to a NON-default value and observed: a `??` whose left side is never
     * present is indistinguishable from its own fallback.
     */
    public function optionalOpenApiKeysReachTheBridge(): void
    {
        $client = new FakeHttpClient();
        $psr17 = new Psr17Factory();
        $path = $this->writeSpecFile();

        $params = $this->params();
        $mcp = &$params['rasuvaeff/yii3-mcp'];
        $mcp['tools'] = [];
        $mcp['openapi']['spec_path'] = $path;
        $mcp['openapi']['base_url'] = 'https://api.test/';
        $mcp['openapi']['operations'] = ['getBlogTags'];
        $mcp['openapi']['tool_names'] = ['getBlogTags' => 'blog_tags'];
        $mcp['openapi']['operation_modifier'] = CallbackOperationModifier::class;
        $mcp['openapi']['dry_run'] = ['getBlogTags'];

        try {
            $tester = $this->bridgeTester($params, new SimpleContainer([
                ClientInterface::class => $client,
                RequestFactoryInterface::class => $psr17,
                StreamFactoryInterface::class => $psr17,
                CallbackOperationModifier::class => new CallbackOperationModifier(
                    static fn(Operation $operation, Tool $tool): Tool => new Tool(
                        name: $tool->name . '_v2',
                        title: $tool->title,
                        inputSchema: $tool->inputSchema,
                        description: $tool->description,
                        annotations: $tool->annotations,
                        outputSchema: $tool->outputSchema,
                    ),
                ),
            ]), $psr17);

            $result = $tester->callTool('blog_tags_v2', ['dryRun' => true]);
        } finally {
            @unlink($path);
        }

        // the modifier ran on top of the tool_names rename…
        Assert::json($result['content'][0]['text'])
            ->isObject()
            ->hasKeys('dryRun', 'operationId', 'method', 'url');

        // …and dry_run kept the call off the wire
        Assert::same($client->requestCount, 0);
    }

    public function safeMethodsOnlyReachesTheBridge(): void
    {
        $psr17 = new Psr17Factory();
        $path = $this->writeSpecFile();

        $params = $this->params();
        $mcp = &$params['rasuvaeff/yii3-mcp'];
        $mcp['tools'] = [];
        $mcp['openapi']['spec_path'] = $path;
        $mcp['openapi']['base_url'] = 'https://api.test/';
        $mcp['openapi']['operations'] = ['createSubscriber'];
        $mcp['openapi']['safe_methods_only'] = true;

        $container = new SimpleContainer([
            ClientInterface::class => new FakeHttpClient(),
            RequestFactoryInterface::class => $psr17,
            StreamFactoryInterface::class => $psr17,
        ]);

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];
        $caught = null;

        try {
            $definition(new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore()), $container);
        } catch (UnsafeOperationException $caught) {
        } finally {
            @unlink($path);
        }

        Assert::notNull($caught);
    }

    /**
     * The URL branch of the spec source, with the two keys only it reads:
     * `spec_headers` (its own credential scope) and `cache_ttl`.
     */
    public function urlSpecIsFetchedWithItsOwnHeadersAndCacheTtl(): void
    {
        $client = new FakeHttpClient(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));
        $cache = new FakeCache();
        $psr17 = new Psr17Factory();

        $params = $this->params();
        $mcp = &$params['rasuvaeff/yii3-mcp'];
        $mcp['tools'] = [];
        $mcp['openapi']['spec_path'] = 'https://spec.test/openapi.json';
        $mcp['openapi']['base_url'] = 'https://api.test/';
        $mcp['openapi']['operations'] = ['getBlogTags'];
        $mcp['openapi']['spec_headers'] = ['X-Spec-Token' => 'spec-only'];
        $mcp['openapi']['headers'] = ['X-Api-Token' => 'api-only'];
        $mcp['openapi']['cache_ttl'] = 60;

        $this->bridgeTester($params, new SimpleContainer([
            ClientInterface::class => $client,
            RequestFactoryInterface::class => $psr17,
            StreamFactoryInterface::class => $psr17,
            CacheInterface::class => $cache,
        ]), $psr17)->callTool('getBlogTags');

        // the operation call carries the operation scope only…
        Assert::same($client->lastRequest?->getHeaderLine('X-Api-Token'), 'api-only');
        Assert::same($client->lastRequest?->getHeaderLine('X-Spec-Token'), '');

        // …and the URL document went through the PSR-16 cache with its TTL
        Assert::same($cache->lastTtl, 60);
        Assert::same(count($cache->values), 1);
    }

    public function omittedOptionalLimitsAndBudgetStayDisabled(): void
    {
        $params = $this->params();
        $mcp = &$params['rasuvaeff/yii3-mcp'];
        unset($mcp['session']['budget'], $mcp['limits']['tool_result_bytes']);
        $mcp['tools'] = [GreetingTool::class];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];
        $container = new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hi')]);
        $factory = new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore());

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();
        $tester = new McpTester($server, $psr17, $psr17, $psr17);
        $result = $tester->callTool('greet', ['name' => 'Yii']);

        Assert::same($result['content'][0]['text'], 'Hi, Yii!');

        // Second call on the same session: an omitted budget must stay
        // disabled, not fall back to a budget of one.
        $second = $tester->callTool('greet', ['name' => 'Yii']);

        Assert::same($second['content'][0]['text'], 'Hi, Yii!');
    }

    public function promptsPathWiresTheMarkdownPromptsConfigurator(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-mcp']['prompts_path'] = __DIR__ . '/Support/prompts';

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];
        $container = new SimpleContainer([]);

        /** @var Server $server */
        $server = $definition(
            new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore()),
            $container,
        );
        $psr17 = new Psr17Factory();
        $names = array_column((new McpTester($server, $psr17, $psr17, $psr17))->listPrompts(), 'name');

        sort($names);

        Assert::same($names, ['code-review', 'plain-note']);
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

        $handler = Understudy::for(RequestHandlerInterface::class);
        when(fn() => $handler->handle(Arg::any()))->returns(new Response(200));

        // Both secret forms empty by default: the middleware must reject
        // every request with the explanatory 503 — fail-closed is the
        // shipped default.
        $response = $middleware->process(new ServerRequest('POST', '/mcp', ['X-Mcp-Secret' => 'anything']), $handler);

        Assert::same($response->getStatusCode(), 503);
        verify(fn() => $handler->handle(Arg::any()), never: true);
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

    public function omittedAppsEnableFlagStaysDisabled(): void
    {
        $params = $this->params();
        unset($params['rasuvaeff/yii3-mcp']['apps']['enable']);

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
    private function writeSpecFile(): string
    {
        $path = sys_get_temp_dir() . '/yii3-mcp-wiring-spec-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bridgeTester(array $params, SimpleContainer $container, Psr17Factory $psr17): McpTester
    {
        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        /** @var Server $server */
        $server = $definition(
            new McpServerFactory(container: $container, sessionStore: new InMemorySessionStore()),
            $container,
        );

        return new McpTester($server, $psr17, $psr17, $psr17);
    }

    /**
     * End-to-end for the in-process executor: the bridged tool call reaches
     * the application's own handler with the same request shape the HTTP
     * executor would send — and the PSR-18 client is never touched.
     */
    public function inProcessExecutorRunsBridgedToolsThroughTheConfiguredHandler(): void
    {
        $psr17 = new Psr17Factory();
        $client = new FakeHttpClient();
        $handler = new RecordingRequestHandler();
        $scope = new RecordingScope();
        $path = $this->writeSpecFile();

        $params = $this->params();
        $mcp = &$params['rasuvaeff/yii3-mcp'];
        $mcp['tools'] = [];
        $mcp['openapi']['spec_path'] = $path;
        $mcp['openapi']['base_url'] = 'https://api.test/';
        $mcp['openapi']['operations'] = ['getBlogTags'];
        $mcp['openapi']['executor'] = 'psr15';
        $mcp['openapi']['handler'] = RecordingRequestHandler::class;
        $mcp['openapi']['identity_provider'] = MutableExecutionIdentityProvider::class;
        $mcp['openapi']['delegated_header_provider'] = IdentityDelegatedHeaderProvider::class;
        $mcp['openapi']['request_attributes'] = SubjectRequestAttributes::class;
        $mcp['openapi']['in_process_scope'] = RecordingScope::class;

        try {
            $tester = $this->bridgeTester($params, new SimpleContainer([
                ClientInterface::class => $client,
                RequestFactoryInterface::class => $psr17,
                StreamFactoryInterface::class => $psr17,
                ServerRequestFactoryInterface::class => $psr17,
                RecordingRequestHandler::class => $handler,
                MutableExecutionIdentityProvider::class => new MutableExecutionIdentityProvider(
                    new ExecutionIdentity(subjectId: 'user-7', tenantId: 'tenant-a'),
                ),
                IdentityDelegatedHeaderProvider::class => new IdentityDelegatedHeaderProvider(),
                SubjectRequestAttributes::class => new SubjectRequestAttributes(),
                RecordingScope::class => $scope,
            ]), $psr17);

            $result = $tester->callTool('getBlogTags');
        } finally {
            @unlink($path);
        }

        $request = $handler->requests[0];

        Assert::same($request->getMethod(), 'GET');
        Assert::same((string) $request->getUri(), 'https://api.test/rest/blog-tags');
        Assert::same($request->getHeaderLine('Accept'), 'application/json');

        // delegated headers AND attributes arrive on the nested request —
        // both from the same identity resolution
        Assert::same($request->getHeaderLine('Authorization'), 'Bearer tenant-a:user-7');
        Assert::same($request->getAttribute('current_user_id'), 'user-7');

        // the handler's body came back through the executor's JSON decode —
        // the text itself stays pretty-printed (result_json default)
        Assert::json($result['content'][0]['text'])->isObject()->hasKeys('ok');

        // the configured scope ran around the nested call
        Assert::same($scope->log, ['enter', 'leave']);

        // no loopback HTTP: the in-process executor never touched PSR-18
        Assert::same($client->requestCount, 0);
    }

    /**
     * Regression for #60: a psr15 server with a LOCAL spec builds and serves
     * in a container that binds no PSR-18 client and no outbound request
     * factory at all — resolving them "just in case" failed the build and
     * contradicted the no-network-I/O contract of the in-process executor.
     */
    public function psr15BuildsWithoutAnyHttpTransportInTheContainer(): void
    {
        $psr17 = new Psr17Factory();
        $handler = new RecordingRequestHandler();
        $path = $this->writeSpecFile();

        $params = $this->params();
        $mcp = &$params['rasuvaeff/yii3-mcp'];
        $mcp['tools'] = [];
        $mcp['openapi']['spec_path'] = $path;
        $mcp['openapi']['base_url'] = 'https://api.test/';
        $mcp['openapi']['operations'] = ['getBlogTags'];
        $mcp['openapi']['executor'] = 'psr15';
        $mcp['openapi']['handler'] = RecordingRequestHandler::class;

        try {
            // NO ClientInterface, NO RequestFactoryInterface — SimpleContainer
            // throws on unresolved ids, so their absence is the assertion
            $result = $this->bridgeTester($params, new SimpleContainer([
                ServerRequestFactoryInterface::class => $psr17,
                StreamFactoryInterface::class => $psr17,
                RecordingRequestHandler::class => $handler,
            ]), $psr17)->callTool('getBlogTags');
        } finally {
            @unlink($path);
        }

        Assert::same(count($handler->requests), 1);
        Assert::json($result['content'][0]['text'])->isObject()->hasKeys('ok');
    }

    /**
     * psr15 with a URL spec: the executor is in-process, but the spec fetch
     * is HTTP — the transport services must still be resolved for it.
     */
    public function psr15WithAUrlSpecStillResolvesTheTransportForTheFetch(): void
    {
        $psr17 = new Psr17Factory();
        $client = new FakeHttpClient(body: json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));
        $path = $this->writeSpecFile();
        @unlink($path);

        $params = $this->params();
        $mcp = &$params['rasuvaeff/yii3-mcp'];
        $mcp['tools'] = [];
        $mcp['openapi']['spec_path'] = 'https://spec.test/openapi.json';
        $mcp['openapi']['base_url'] = 'https://api.test/';
        $mcp['openapi']['operations'] = ['getBlogTags'];
        $mcp['openapi']['executor'] = 'psr15';
        $mcp['openapi']['handler'] = RecordingRequestHandler::class;

        $handler = new RecordingRequestHandler();
        $this->bridgeTester($params, new SimpleContainer([
            ClientInterface::class => $client,
            RequestFactoryInterface::class => $psr17,
            StreamFactoryInterface::class => $psr17,
            ServerRequestFactoryInterface::class => $psr17,
            RecordingRequestHandler::class => $handler,
        ]), $psr17)->callTool('getBlogTags');

        // the spec was fetched over HTTP, the operation was not
        Assert::same($client->requestCount, 1);
        Assert::same(count($handler->requests), 1);
    }
    /**
     * The #58 acceptance: tools/list advertises the array schema (type,
     * items, enum, maxItems) exactly as the OpenAPI document declares it.
     */
    public function arrayQueryToolsAdvertiseTheArraySchema(): void
    {
        $psr17 = new Psr17Factory();
        $path = $this->writeSpecFile();

        $params = $this->params();
        $mcp = &$params['rasuvaeff/yii3-mcp'];
        $mcp['tools'] = [];
        $mcp['openapi']['spec_path'] = $path;
        $mcp['openapi']['base_url'] = 'https://api.test/';
        $mcp['openapi']['operations'] = ['getCreators'];

        try {
            $tools = $this->bridgeTester($params, new SimpleContainer([
                ClientInterface::class => new FakeHttpClient(),
                RequestFactoryInterface::class => $psr17,
                StreamFactoryInterface::class => $psr17,
            ]), $psr17)->listTools();
        } finally {
            @unlink($path);
        }

        $tool = null;

        foreach ($tools as $candidate) {
            if (($candidate['name'] ?? null) === 'getCreators') {
                $tool = $candidate;
            }
        }

        Assert::notNull($tool);

        $platforms = $tool['inputSchema']['properties']['platforms'] ?? null;

        Assert::same($platforms['type'] ?? null, 'array');
        Assert::same($platforms['maxItems'] ?? null, 3);
        Assert::same($platforms['items']['enum'] ?? null, ['instagram', 'tiktok', 'youtube']);
    }

    public function arrayQueryStyleReachesTheBridge(): void
    {
        $psr17 = new Psr17Factory();
        $client = new FakeHttpClient();
        $path = $this->writeSpecFile();

        $params = $this->params();
        $mcp = &$params['rasuvaeff/yii3-mcp'];
        $mcp['tools'] = [];
        $mcp['openapi']['spec_path'] = $path;
        $mcp['openapi']['base_url'] = 'https://api.test/';
        $mcp['openapi']['operations'] = ['getCreators'];
        $mcp['openapi']['array_query_style'] = 'brackets';

        try {
            $this->bridgeTester($params, new SimpleContainer([
                ClientInterface::class => $client,
                RequestFactoryInterface::class => $psr17,
                StreamFactoryInterface::class => $psr17,
            ]), $psr17)->callTool('getCreators', ['platforms' => ['instagram', 'youtube']]);
        } finally {
            @unlink($path);
        }

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/rest/creators?platforms%5B%5D=instagram&platforms%5B%5D=youtube');
    }
    public function anInvalidArrayQueryStyleFailsTheServerBuild(): void
    {
        $psr17 = new Psr17Factory();
        $path = $this->writeSpecFile();

        $params = $this->params();
        $mcp = &$params['rasuvaeff/yii3-mcp'];
        $mcp['tools'] = [];
        $mcp['openapi']['spec_path'] = $path;
        $mcp['openapi']['base_url'] = 'https://api.test/';
        $mcp['openapi']['operations'] = ['getCreators'];
        $mcp['openapi']['array_query_style'] = 'pipes';

        $caught = null;

        try {
            $this->bridgeTester($params, new SimpleContainer([
                ClientInterface::class => new FakeHttpClient(),
                RequestFactoryInterface::class => $psr17,
                StreamFactoryInterface::class => $psr17,
            ]), $psr17);
        } catch (\InvalidArgumentException $caught) {
        } finally {
            @unlink($path);
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('repeat, brackets, comma');
    }

    /**
     * psr15 mode requires the handler service.
     */
    public function psr15ExecutorRequiresAHandler(): void
    {
        $psr17 = new Psr17Factory();
        $path = $this->writeSpecFile();

        $params = $this->params();
        $mcp = &$params['rasuvaeff/yii3-mcp'];
        $mcp['tools'] = [];
        $mcp['openapi']['spec_path'] = $path;
        $mcp['openapi']['base_url'] = 'https://api.test/';
        $mcp['openapi']['operations'] = ['getBlogTags'];
        $mcp['openapi']['executor'] = 'psr15';

        $caught = null;

        try {
            $this->bridgeTester($params, new SimpleContainer([
                ClientInterface::class => new FakeHttpClient(),
                RequestFactoryInterface::class => $psr17,
                StreamFactoryInterface::class => $psr17,
                ServerRequestFactoryInterface::class => $psr17,
            ]), $psr17);
        } catch (LogicException $caught) {
        } finally {
            @unlink($path);
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('requires openapi.handler');
    }

    public function httpModeRejectsInProcessOnlyConfiguration(): void
    {
        $psr17 = new Psr17Factory();
        $path = $this->writeSpecFile();

        $params = $this->params();
        $mcp = &$params['rasuvaeff/yii3-mcp'];
        $mcp['tools'] = [];
        $mcp['openapi']['spec_path'] = $path;
        $mcp['openapi']['base_url'] = 'https://api.test/';
        $mcp['openapi']['operations'] = ['getBlogTags'];
        $mcp['openapi']['handler'] = RecordingRequestHandler::class;

        $caught = null;

        try {
            $this->bridgeTester($params, new SimpleContainer([
                ClientInterface::class => new FakeHttpClient(),
                RequestFactoryInterface::class => $psr17,
                StreamFactoryInterface::class => $psr17,
                RecordingRequestHandler::class => new RecordingRequestHandler(),
            ]), $psr17);
        } catch (LogicException $caught) {
        } finally {
            @unlink($path);
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('only read when openapi.executor is "psr15"');
    }

    /**
     * Each of the three psr15-only keys alone must fail http mode — a check
     * wired with `||` that only the first key can trip would let the other
     * two through silently.
     */
    public function httpModeRejectsEachInProcessOnlyKeyAlone(): void
    {
        $psr17 = new Psr17Factory();

        foreach (['request_attributes' => SubjectRequestAttributes::class, 'in_process_scope' => RecordingScope::class] as $key => $class) {
            $path = $this->writeSpecFile();

            $params = $this->params();
            $mcp = &$params['rasuvaeff/yii3-mcp'];
            $mcp['tools'] = [];
            $mcp['openapi']['spec_path'] = $path;
            $mcp['openapi']['base_url'] = 'https://api.test/';
            $mcp['openapi']['operations'] = ['getBlogTags'];
            $mcp['openapi'][$key] = $class;

            $caught = null;

            try {
                $this->bridgeTester($params, new SimpleContainer([
                    ClientInterface::class => new FakeHttpClient(),
                    RequestFactoryInterface::class => $psr17,
                    StreamFactoryInterface::class => $psr17,
                    SubjectRequestAttributes::class => new SubjectRequestAttributes(),
                    RecordingScope::class => new RecordingScope(),
                ]), $psr17);
            } catch (LogicException $caught) {
            } finally {
                @unlink($path);
            }

            Assert::notNull($caught);
            Assert::string($caught->getMessage())->contains('only read when openapi.executor is "psr15"');
        }
    }

    public function unsupportedExecutorModeFailsTheServerBuild(): void
    {
        $psr17 = new Psr17Factory();
        $path = $this->writeSpecFile();

        $params = $this->params();
        $mcp = &$params['rasuvaeff/yii3-mcp'];
        $mcp['tools'] = [];
        $mcp['openapi']['spec_path'] = $path;
        $mcp['openapi']['base_url'] = 'https://api.test/';
        $mcp['openapi']['operations'] = ['getBlogTags'];
        $mcp['openapi']['executor'] = 'grpc';

        $caught = null;

        try {
            $this->bridgeTester($params, new SimpleContainer([
                ClientInterface::class => new FakeHttpClient(),
                RequestFactoryInterface::class => $psr17,
                StreamFactoryInterface::class => $psr17,
            ]), $psr17);
        } catch (LogicException $caught) {
        } finally {
            @unlink($path);
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('Unsupported openapi.executor "grpc"');
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function weatherResult(array $params): array
    {
        $params['rasuvaeff/yii3-mcp']['tools'] = [StructuredWeatherTool::class];

        /** @var Closure $definition */
        $definition = $this->di($params)[Server::class]['definition'];

        $container = new SimpleContainer([StructuredWeatherTool::class => new StructuredWeatherTool()]);
        $factory = new McpServerFactory(
            container: $container,
            sessionStore: new InMemorySessionStore(),
            compactToolResults: ($params['rasuvaeff/yii3-mcp']['result_json'] ?? 'pretty') === McpServerFactory::RESULT_JSON_COMPACT,
        );

        /** @var Server $server */
        $server = $definition($factory, $container);
        $psr17 = new Psr17Factory();

        return (new McpTester($server, $psr17, $psr17, $psr17))->callTool('weather', ['city' => 'Rome']);
    }

    private function params(): array
    {
        return require __DIR__ . '/../config/params.php';
    }

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    private function di(?array $params = null): array
    {
        $params ??= $this->params();

        return (static fn(array $params): array => require __DIR__ . '/../config/di.php')($params);
    }
}
