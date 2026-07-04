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
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingInterceptor;
use Rasuvaeff\Yii3Mcp\Tests\Support\ShortCircuitInterceptor;
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

    /**
     * @param list<ToolCallInterceptorInterface> $interceptors
     */
    private function tester(array $interceptors): McpTester
    {
        $factory = new Psr17Factory();

        return new McpTester(
            server: $this->server($interceptors),
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    /**
     * @param list<ToolCallInterceptorInterface> $interceptors
     */
    private function server(array $interceptors): Server
    {
        return (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
            name: 'interceptor-suite',
            version: '1.0.0',
        ))->create([GreetingTool::class], [], $interceptors);
    }
}
