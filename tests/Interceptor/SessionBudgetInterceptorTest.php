<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use InvalidArgumentException;
use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\Interceptor\SessionBudgetInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeSession;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(SessionBudgetInterceptor::class)]
final class SessionBudgetInterceptorTest
{
    public function allowsCallsWithinTheBudget(): void
    {
        $tester = $this->tester(budget: 2);

        Assert::same($tester->callTool('greet', ['name' => 'One'])['content'][0]['text'], 'Hello, One!');
        Assert::same($tester->callTool('greet', ['name' => 'Two'])['content'][0]['text'], 'Hello, Two!');
    }

    public function exhaustedBudgetBecomesToolErrorEnvelope(): void
    {
        $tester = $this->tester(budget: 2);
        $tester->callTool('greet', ['name' => 'One']);
        $tester->callTool('greet', ['name' => 'Two']);

        $result = $tester->callTool('greet', ['name' => 'Three']);

        Assert::true($result['isError']);
        Assert::string($result['content'][0]['text'])->contains('budget of 2 is exhausted');
    }

    public function freshSessionStartsAFreshCounter(): void
    {
        $server = $this->server(budget: 1);
        $factory = new Psr17Factory();

        $first = new McpTester($server, $factory, $factory, $factory);
        $first->callTool('greet', ['name' => 'One']);
        Assert::true($first->callTool('greet', ['name' => 'Two'])['isError']);

        $second = new McpTester($server, $factory, $factory, $factory);
        $result = $second->callTool('greet', ['name' => 'Three']);

        Assert::same($result['content'][0]['text'], 'Hello, Three!');
    }

    public function passesThroughWithoutASession(): void
    {
        $interceptor = new SessionBudgetInterceptor(budget: 1);
        $context = new ToolCallContext(toolName: 'x', arguments: []);

        Assert::same($interceptor->intercept($context, static fn(): string => 'a'), 'a');
        Assert::same($interceptor->intercept($context, static fn(): string => 'b'), 'b');
    }

    public function corruptedCounterIsTreatedAsZero(): void
    {
        $interceptor = new SessionBudgetInterceptor(budget: 1);
        $session = new FakeSession(['rasuvaeff.yii3-mcp.tool-calls' => 'garbage']);
        $context = new ToolCallContext(toolName: 'x', arguments: [], session: $session);

        Assert::same($interceptor->intercept($context, static fn(): string => 'ok'), 'ok');
        Assert::same($session->get('rasuvaeff.yii3-mcp.tool-calls'), 1);
    }

    public function countsTheAttemptBeforeExecuting(): void
    {
        $interceptor = new SessionBudgetInterceptor(budget: 5);
        $session = new FakeSession();
        $context = new ToolCallContext(toolName: 'x', arguments: [], session: $session);

        $interceptor->intercept($context, static fn(): string => 'ok');
        $interceptor->intercept($context, static fn(): string => 'ok');

        Assert::same($session->get('rasuvaeff.yii3-mcp.tool-calls'), 2);
    }

    public function failedCallStillConsumesTheBudget(): void
    {
        $interceptor = new SessionBudgetInterceptor(budget: 5);
        $session = new FakeSession();
        $context = new ToolCallContext(toolName: 'x', arguments: [], session: $session);

        $caught = null;

        try {
            $interceptor->intercept($context, static fn(): string => throw new RuntimeException('downstream failed'));
        } catch (RuntimeException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($session->get('rasuvaeff.yii3-mcp.tool-calls'), 1);
    }

    #[DataProvider('invalidBudgetProvider')]
    public function throwsOnNonPositiveBudget(int $budget): void
    {
        $caught = null;

        try {
            new SessionBudgetInterceptor($budget);
        } catch (InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('at least 1');
    }

    public static function invalidBudgetProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
    }

    private function tester(int $budget): McpTester
    {
        $factory = new Psr17Factory();

        return new McpTester(
            server: $this->server($budget),
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    private function server(int $budget): Server
    {
        return (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
            name: 'budget-suite',
            version: '1.0.0',
        ))->create([GreetingTool::class], [], [new SessionBudgetInterceptor($budget)]);
    }
}
