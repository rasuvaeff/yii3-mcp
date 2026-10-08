<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Mcp\Exception\ToolCallException;
use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Mcp\Identity\ClientIdentityContext;
use Rasuvaeff\Yii3Mcp\Interceptor\InterceptingReferenceHandler;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\CountingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyListVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\ReadyResultTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingInterceptor;
use Rasuvaeff\Yii3Mcp\Tests\Support\StructuredWeatherTool;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(InterceptingReferenceHandler::class)]
final class InterceptingReferenceHandlerTest
{
    public function interceptorWrapsTheToolCall(): void
    {
        $recording = new RecordingInterceptor();

        $result = $this->tester([$recording])->callTool('greet', ['name' => 'Yii']);

        Assert::same($result['content'][0]['text'], 'Hello, Yii!');
        Assert::same($recording->entries, ['interceptor:before:greet', 'interceptor:after:greet']);
    }

    public function compactToolResultsReEncodeArrayResults(): void
    {
        $factory = new Psr17Factory();

        $server = (new McpServerFactory(
            container: new SimpleContainer([StructuredWeatherTool::class => new StructuredWeatherTool()]),
            sessionStore: new InMemorySessionStore(),
            compactToolResults: true,
        ))->create([StructuredWeatherTool::class]);

        $result = (new McpTester($server, $factory, $factory, $factory))->callTool('weather', ['city' => 'Rome']);

        Assert::same($result['content'][0]['text'], '{"city":"Rome","temperature":21,"conditions":"sunny"}');
        Assert::same($result['structuredContent'] ?? null, ['city' => 'Rome', 'temperature' => 21, 'conditions' => 'sunny']);
    }

    public function compactToolResultsPassAReadyCallToolResultThrough(): void
    {
        $factory = new Psr17Factory();

        $server = (new McpServerFactory(
            container: new SimpleContainer([ReadyResultTool::class => new ReadyResultTool()]),
            sessionStore: new InMemorySessionStore(),
            compactToolResults: true,
        ))->create([ReadyResultTool::class]);

        $result = (new McpTester($server, $factory, $factory, $factory))->callTool('ready', []);

        // the tool's own CallToolResult is served untouched — re-formatting it
        // would wrap or re-encode what the tool already decided
        Assert::same($result['content'][0]['text'], 'ready-made');
        Assert::false(isset($result['structuredContent']));
    }

    public function arrayResultsStayPrettyWhenCompactModeIsOff(): void
    {
        $factory = new Psr17Factory();

        // an interceptor installs the SAME decorator; with compact mode off
        // its array results must keep the SDK's pretty formatting
        $server = (new McpServerFactory(
            container: new SimpleContainer([StructuredWeatherTool::class => new StructuredWeatherTool()]),
            sessionStore: new InMemorySessionStore(),
        ))->create([StructuredWeatherTool::class], [], [new RecordingInterceptor()]);

        $result = (new McpTester($server, $factory, $factory, $factory))->callTool('weather', ['city' => 'Rome']);

        Assert::string($result['content'][0]['text'])->contains("\n");
        Assert::same($result['structuredContent'] ?? null, ['city' => 'Rome', 'temperature' => 21, 'conditions' => 'sunny']);
    }

    public function interceptorsRunInConfiguredOrderFirstOutermost(): void
    {
        $outer = new RecordingInterceptor('outer');
        $inner = new RecordingInterceptor('inner', timeline: $outer);

        $this->tester([$outer, $inner])->callTool('greet', ['name' => 'Yii']);

        Assert::same($outer->entries, [
            'outer:before:greet',
            'inner:before:greet',
            'inner:after:greet',
            'outer:after:greet',
        ]);
    }

    public function contextCarriesToolNameArgumentsAndClientInfo(): void
    {
        $recording = new RecordingInterceptor();

        $this->tester([$recording])->callTool('greet', ['name' => 'Yii']);

        $context = $recording->lastContext;
        Assert::notNull($context);
        Assert::same($context->toolName, 'greet');
        Assert::same($context->arguments, ['name' => 'Yii']);
        Assert::same($context->clientInfo()?->name, 'mcp-tester');
    }

    public function armedClientIdentityReachesTheContextAndSession(): void
    {
        $recording = new RecordingInterceptor();
        $tester = $this->tester([$recording]);

        ClientIdentityContext::arm('claude');

        try {
            $tester->callTool('greet', ['name' => 'Yii']);
        } finally {
            ClientIdentityContext::disarm();
        }

        Assert::same($recording->lastContext?->clientId, 'claude');
    }

    public function sessionKeepsTheClientIdWhenTheHolderIsDisarmed(): void
    {
        $recording = new RecordingInterceptor();
        $tester = $this->tester([$recording]);

        ClientIdentityContext::arm('claude');

        try {
            $tester->callTool('greet', ['name' => 'Yii']);
        } finally {
            ClientIdentityContext::disarm();
        }

        // A later request in the SAME session without an armed holder (e.g.
        // stdio or a misordered middleware stack) still resolves via the
        // session mirror.
        $tester->callTool('greet', ['name' => 'Again']);

        Assert::same($recording->lastContext?->clientId, 'claude');
    }

    public function sessionOwnerRefusesAConflictingArmedIdentity(): void
    {
        $recording = new RecordingInterceptor();
        $tester = $this->tester([$recording]);

        ClientIdentityContext::arm('claude');

        try {
            $tester->callTool('greet', ['name' => 'Yii']);
        } finally {
            ClientIdentityContext::disarm();
        }

        // Fiber-interleaving regression: request B's identity lands in the
        // process-local slot while session A's call executes. The session's
        // immutable owner wins and the call fails closed instead of being
        // silently re-attributed to B.
        ClientIdentityContext::arm('mallory');
        $failedClosed = false;

        try {
            $result = $tester->callTool('greet', ['name' => 'Yii']);
            $failedClosed = ($result['isError'] ?? false) === true;
        } catch (\Throwable) {
            $failedClosed = true;
        } finally {
            ClientIdentityContext::disarm();
        }

        Assert::true($failedClosed);
        // the interceptor chain never observed the conflicting identity
        Assert::same($recording->lastContext?->clientId, 'claude');
    }

    public function withoutIdentityTheContextClientIdIsNull(): void
    {
        $recording = new RecordingInterceptor();

        $this->tester([$recording])->callTool('greet', ['name' => 'Yii']);

        Assert::null($recording->lastContext?->clientId);
    }

    public function promptsAndResourcesBypassTheChain(): void
    {
        $recording = new RecordingInterceptor();
        $tester = $this->tester([$recording]);

        $tester->readResource('app://status');
        $tester->request('prompts/get', ['name' => 'greeting-style']);

        Assert::same($recording->entries, []);
    }

    public function shortCircuitReturnsWithoutExecutingTheTool(): void
    {
        $interceptor = Understudy::for(ToolCallInterceptorInterface::class);
        when(fn() => $interceptor->intercept(Arg::any(), Arg::any()))->returns('from-interceptor');

        $result = $this->tester([$interceptor])->callTool('explode');

        Assert::same($result['content'][0]['text'], 'from-interceptor');
        Assert::false($result['isError'] ?? false);
    }

    public function toolCallExceptionBecomesErrorEnvelope(): void
    {
        $interceptor = Understudy::for(ToolCallInterceptorInterface::class);
        when(fn() => $interceptor->intercept(Arg::any(), Arg::any()))->throws(new ToolCallException('rejected by policy'));

        $result = $this->tester([$interceptor])->callTool('greet', ['name' => 'Yii']);

        Assert::true($result['isError']);
        Assert::same($result['content'][0]['text'], 'rejected by policy');
    }

    public function noInterceptorsMeansUntouchedBehavior(): void
    {
        $result = $this->tester([])->callTool('greet', ['name' => 'Yii']);

        Assert::same($result['content'][0]['text'], 'Hello, Yii!');
    }

    public function invisibleToolCannotBeCalledEvenByExactName(): void
    {
        $tester = $this->tester([], new DenyListVisibility(hidden: ['greet']));

        // answered exactly like a missing tool (-32602, the SDK's own message)
        Assert::same($this->callError($tester, 'greet'), 'MCP error: Tool not found: "greet".');
    }

    public function visibleToolPassesTheVisibilityCheck(): void
    {
        $tester = $this->tester([], new DenyListVisibility(hidden: ['explode']));

        $result = $tester->callTool('greet', ['name' => 'Yii']);

        Assert::same($result['content'][0]['text'], 'Hello, Yii!');
    }

    public function toolExecutesExactlyOnceUnderVisibilityWithoutInterceptors(): void
    {
        $counting = new CountingTool();
        $server = (new McpServerFactory(
            container: new SimpleContainer([CountingTool::class => $counting]),
            sessionStore: new InMemorySessionStore(),
            name: 'interceptor-suite',
            version: '1.0.0',
        ))->create([CountingTool::class], [], [], new DenyListVisibility());

        $factory = new Psr17Factory();
        $result = (new McpTester($server, $factory, $factory, $factory))->callTool('count.up');

        Assert::same($result['content'][0]['text'], '1');
        Assert::same($counting->calls, 1);
    }

    public function deniedCallNeverReachesTheInterceptors(): void
    {
        $recording = new RecordingInterceptor();
        $tester = $this->tester([$recording], new DenyListVisibility(hidden: ['greet']));

        Assert::same($this->callError($tester, 'greet'), 'MCP error: Tool not found: "greet".');
        Assert::same($recording->entries, []);
    }

    private function callError(McpTester $tester, string $tool): string
    {
        try {
            $tester->callTool($tool, ['name' => 'Yii']);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return 'no error';
    }

    /**
     * @param list<ToolCallInterceptorInterface> $interceptors
     */
    private function tester(array $interceptors, ?ToolVisibilityInterface $visibility = null): McpTester
    {
        $factory = new Psr17Factory();

        return new McpTester(
            server: $this->server($interceptors, $visibility),
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    /**
     * @param list<ToolCallInterceptorInterface> $interceptors
     */
    private function server(array $interceptors, ?ToolVisibilityInterface $visibility): Server
    {
        return (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
            name: 'interceptor-suite',
            version: '1.0.0',
        ))->create([GreetingTool::class], [], $interceptors, $visibility);
    }
}
