<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\Interceptor\InterceptingReferenceHandler;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\CountingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyListVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingInterceptor;
use Rasuvaeff\Yii3Mcp\Tests\Support\ShortCircuitInterceptor;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

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
        Assert::same($context->getClientInfo()['name'] ?? null, 'mcp-tester');
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
        $result = $this->tester([new ShortCircuitInterceptor(result: 'from-interceptor')])
            ->callTool('explode');

        Assert::same($result['content'][0]['text'], 'from-interceptor');
        Assert::false($result['isError'] ?? false);
    }

    public function toolCallExceptionBecomesErrorEnvelope(): void
    {
        $result = $this->tester([new ShortCircuitInterceptor(rejectWith: 'rejected by policy')])
            ->callTool('greet', ['name' => 'Yii']);

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

        $result = $tester->callTool('greet', ['name' => 'Yii']);

        Assert::true($result['isError']);
        Assert::string($result['content'][0]['text'])->contains('"greet" is not available in this session');
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

        $result = $tester->callTool('greet', ['name' => 'Yii']);

        Assert::true($result['isError']);
        Assert::same($recording->entries, []);
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
