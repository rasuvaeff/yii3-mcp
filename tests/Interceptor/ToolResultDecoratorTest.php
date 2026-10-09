<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\Interceptor\CachingToolCallInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\InterceptingReferenceHandler;
use Rasuvaeff\Yii3Mcp\Interceptor\ResponseSizeLimitInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolResultDecoratorInterface;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\ConfirmingInterceptor;
use Rasuvaeff\Yii3Mcp\Tests\Support\CountingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeCache;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\IdentityDecorator;
use Rasuvaeff\Yii3Mcp\Tests\Support\LinkingDecorator;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingInterceptor;
use Rasuvaeff\Yii3Mcp\Tests\Support\ShapeTool;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

/**
 * Result decorators get the FORMATTED result after the whole chain. Without
 * compact mode the package then builds the pretty result itself — so an
 * identity decorator is the regression guard: its presence must not change a
 * byte of any result shape, on either era and in either result_json mode.
 */
#[Test]
#[Covers(InterceptingReferenceHandler::class)]
#[Covers(McpServerFactory::class)]
final class ToolResultDecoratorTest
{
    /**
     * @return iterable<string, array{string, ?ProtocolVersion, bool}>
     */
    public static function shapesErasAndModes(): iterable
    {
        foreach (ShapeTool::KINDS as $kind) {
            foreach (['handshake' => null, 'stateless' => ProtocolVersion::V2026_07_28] as $era => $revision) {
                foreach (['pretty' => false, 'compact' => true] as $mode => $compact) {
                    yield sprintf('%s, %s, %s', $kind, $era, $mode) => [$kind, $revision, $compact];
                }
            }
        }
    }

    #[DataProvider('shapesErasAndModes')]
    public function anIdentityDecoratorChangesNothing(string $kind, ?ProtocolVersion $revision, bool $compact): void
    {
        $identity = new IdentityDecorator();

        $plain = $this->tester(compact: $compact, revision: $revision)->callTool('shape', ['kind' => $kind]);
        $decorated = $this->tester(compact: $compact, revision: $revision, decorators: [$identity])->callTool('shape', ['kind' => $kind]);

        Assert::same($decorated, $plain);
        Assert::same($identity->calls, 1);
    }

    public function aHandshakeListIsStillNotStructuredContent(): void
    {
        $result = $this->tester(decorators: [new LinkingDecorator()])->callTool('shape', ['kind' => 'list']);

        Assert::false(array_key_exists('structuredContent', $result));
        Assert::same($result['content'][1]['type'] ?? null, 'resource_link');
    }

    /**
     * Decorators alone — no interceptor, no visibility, pretty mode — still
     * install the package's reference handler; otherwise they would be
     * silently ignored.
     */
    public function decoratorsAloneAppendContentInPrettyMode(): void
    {
        $result = $this->tester(decorators: [new LinkingDecorator()])->callTool('shape', ['kind' => 'assoc']);

        Assert::same($result['content'][0]['text'] ?? null, "{\n    \"city\": \"Rome\",\n    \"temperature\": 21\n}");
        Assert::same($result['content'][1] ?? null, ['type' => 'resource_link', 'uri' => 'app://link', 'name' => 'link']);
        Assert::same($result['structuredContent'] ?? null, ['city' => 'Rome', 'temperature' => 21]);
    }

    public function compactModeTextIsDecoratedToo(): void
    {
        $result = $this->tester(compact: true, decorators: [new LinkingDecorator()])->callTool('shape', ['kind' => 'assoc']);

        Assert::same($result['content'][0]['text'] ?? null, '{"city":"Rome","temperature":21}');
        Assert::same($result['content'][1]['uri'] ?? null, 'app://link');
    }

    public function decoratorsRunInConfiguredOrderWithTheCallContext(): void
    {
        $first = new LinkingDecorator(name: 'first');
        $second = new LinkingDecorator(name: 'second');

        $result = $this->tester(decorators: [$first, $second])->callTool('shape', ['kind' => 'string']);

        Assert::same(array_column($result['content'], 'uri'), ['app://first', 'app://second']);
        Assert::same($first->seen, [['tool' => 'shape', 'arguments' => ['kind' => 'string'], 'isError' => false, 'content' => 1]]);
        Assert::same($second->seen[0]['content'], 2);
    }

    public function aHandlerReturnedErrorResultIsDecorated(): void
    {
        $decorator = new LinkingDecorator();

        $result = $this->tester(decorators: [$decorator])->callTool('shape', ['kind' => 'ready_error']);

        Assert::true($result['isError'] ?? false);
        Assert::true($decorator->seen[0]['isError']);
    }

    public function aToolCallExceptionNeverReachesADecorator(): void
    {
        $decorator = new LinkingDecorator();

        $result = $this->tester(decorators: [$decorator])->callTool('shape', ['kind' => 'unknown']);

        Assert::true($result['isError'] ?? false);
        Assert::same($decorator->seen, []);
    }

    /**
     * The result cache stores the raw handler result: a hit is decorated like
     * a miss, and the tool runs once.
     */
    public function aCacheHitIsDecoratedEveryTime(): void
    {
        $counting = new CountingTool();
        $decorator = new LinkingDecorator();
        $cache = new CachingToolCallInterceptor(cache: new FakeCache(), ttlSeconds: ['count.up' => 60], namespace: 'test');
        $tester = $this->tester(decorators: [$decorator], interceptors: [$cache], counting: $counting);

        $first = $tester->callTool('count.up');
        $second = $tester->callTool('count.up');

        Assert::same($counting->calls, 1);
        Assert::same($second, $first);
        Assert::same($first['content'][1]['uri'] ?? null, 'app://link');
        Assert::same(count($decorator->seen), 2);
    }

    /**
     * limits.tool_result_bytes measures the raw result inside the chain;
     * what a decorator adds afterwards is not truncated.
     */
    public function addedContentDoesNotCountAgainstTheSizeLimit(): void
    {
        $limit = new ResponseSizeLimitInterceptor(maxBytes: 5);

        $result = $this->tester(decorators: [new LinkingDecorator(name: str_repeat('x', 100))], interceptors: [$limit])->callTool('shape', ['kind' => 'string']);

        Assert::same($result['content'][0]['text'] ?? null, 'plain');
        Assert::same($result['content'][1]['name'] ?? null, str_repeat('x', 100));
    }

    /**
     * The ask of a multi round-trip call is not tool output: only the final
     * round's result reaches decorators.
     */
    public function anAskIsNeverDecoratedTheFinalRoundIs(): void
    {
        $decorator = new LinkingDecorator();
        $tester = $this->tester(revision: ProtocolVersion::V2026_07_28, decorators: [$decorator], interceptors: [new ConfirmingInterceptor()]);

        $ask = $tester->callTool('greet', ['name' => 'Yii']);
        Assert::same($ask['resultType'] ?? null, 'input_required');
        Assert::same($decorator->seen, []);

        $final = $tester->request('tools/call', [
            'name' => 'greet',
            'arguments' => ['name' => 'Yii'],
            'inputResponses' => ['confirm-reveal' => ['action' => 'accept', 'content' => ['confirm' => true]]],
        ]);

        Assert::same($final['content'][1]['uri'] ?? null, 'app://link');
        Assert::same(count($decorator->seen), 1);
    }

    /**
     * Pretty mode without decorators must keep handing the RAW result on —
     * the SDK formats it, exactly as before decorators existed; with one, the
     * package builds the CallToolResult itself.
     */
    public function prettyModeBuildsTheResultInPackageOnlyForDecorators(): void
    {
        $reference = new ToolReference(
            new Tool(name: 'weather', title: null, inputSchema: ['type' => 'object', 'properties' => new \stdClass()], description: null, annotations: null),
            static fn(): array => ['city' => 'Rome'],
        );
        $inner = new class implements ReferenceHandlerInterface {
            #[\Override]
            public function handle(ElementReference $reference, array $arguments): mixed
            {
                return ['city' => 'Rome'];
            }
        };

        $raw = (new InterceptingReferenceHandler(inner: $inner, interceptors: [new RecordingInterceptor()]))->handle($reference, []);
        $decorated = (new InterceptingReferenceHandler(inner: $inner, interceptors: [], resultDecorators: [new IdentityDecorator()]))->handle($reference, []);

        Assert::same($raw, ['city' => 'Rome']);
        Assert::instanceOf($decorated, CallToolResult::class);
        Assert::same($decorated instanceof CallToolResult ? $decorated->structuredContent : null, ['city' => 'Rome']);
    }

    /**
     * @param list<ToolResultDecoratorInterface> $decorators
     * @param list<ToolCallInterceptorInterface> $interceptors
     */
    private function tester(
        bool $compact = false,
        ?ProtocolVersion $revision = null,
        array $decorators = [],
        array $interceptors = [],
        ?CountingTool $counting = null,
    ): McpTester {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([
                ShapeTool::class => new ShapeTool(),
                GreetingTool::class => new GreetingTool(prefix: 'Hello'),
                CountingTool::class => $counting ?? new CountingTool(),
            ]),
            sessionStore: new InMemorySessionStore(),
            compactToolResults: $compact,
        ))->create(
            toolClasses: [ShapeTool::class, GreetingTool::class, CountingTool::class],
            interceptors: $interceptors,
            resultDecorators: $decorators,
        );

        return new McpTester(
            server: $server,
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
            protocolVersion: $revision,
            capabilities: new ClientCapabilities(elicitation: true),
        );
    }
}
