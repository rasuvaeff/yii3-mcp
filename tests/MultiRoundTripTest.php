<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallBudgetInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpErrorException;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\ElicitingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeCache;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

/**
 * A tool that asks the user mid-call, on the stateless era: the ask ends the
 * request with `input_required`, the client re-sends the call with the
 * answer, and the SDK runs the handler again from the top. Driven through
 * the real endpoint with the tester answering by hand, as a client would.
 */
#[Test]
#[Covers(McpServerFactory::class)]
#[Covers(McpTester::class)]
final class MultiRoundTripTest
{
    private const string KEY = 'a-request-state-signing-key-of-32+-bytes';

    public function anAskEndsTheRequestWithInputRequired(): void
    {
        $result = $this->tester()->callTool('order.delete', ['orderId' => '42']);

        Assert::same($result['resultType'] ?? null, 'input_required');
        Assert::same($result['inputRequests']['confirm']['method'] ?? null, 'elicitation/create');
    }

    public function theRetryCarryingTheAnswerCompletesTheCall(): void
    {
        $tool = new ElicitingTool();
        $tester = $this->tester($tool);

        $tester->callTool('order.delete', ['orderId' => '42']);
        $result = $this->answer($tester, 'order.delete', ['confirm' => ['action' => 'accept', 'content' => ['confirm' => true]]]);

        Assert::same($result['content'][0]['text'] ?? null, 'deleted 42');
        // entered once per round — what makes side effects before an ask repeat
        Assert::same($tool->runs, 2);
    }

    public function aDeclinedAnswerIsSeenByTheHandler(): void
    {
        $tester = $this->tester();

        $tester->callTool('order.delete', ['orderId' => '42']);
        $result = $this->answer($tester, 'order.delete', ['confirm' => ['action' => 'decline']]);

        Assert::same($result['content'][0]['text'] ?? null, 'kept 42');
    }

    /**
     * A second ask has to carry the first answer into the next round, in a
     * signed requestState — without a key the call fails instead of losing it.
     */
    public function aSecondAskNeedsTheRequestStateKey(): void
    {
        $tester = $this->tester();

        $tester->callTool('order.rename', ['orderId' => '42']);

        try {
            $this->answer($tester, 'order.rename', ['name' => ['action' => 'accept', 'content' => ['name' => 'urgent']]]);
            $error = null;
        } catch (McpErrorException $error) {
        }

        Assert::instanceOf($error, McpErrorException::class);
    }

    public function withAKeyEarlierAnswersSurviveIntoLaterRounds(): void
    {
        $tester = $this->tester(requestStateKey: self::KEY);

        $tester->callTool('order.rename', ['orderId' => '42']);
        $second = $this->answer($tester, 'order.rename', ['name' => ['action' => 'accept', 'content' => ['name' => 'urgent']]]);

        Assert::same($second['inputRequests']['confirm']['method'] ?? null, 'elicitation/create');
        Assert::true(is_string($second['requestState'] ?? null));

        $third = $this->answer(
            $tester,
            'order.rename',
            ['confirm' => ['action' => 'accept', 'content' => ['confirm' => true]]],
            (string) $second['requestState'],
        );

        Assert::same($third['content'][0]['text'] ?? null, '42 renamed to urgent');
    }

    /**
     * Every round is a tools/call and is counted: the answers are not signed,
     * so a budget that skipped "retries" could be bypassed by any client
     * attaching made-up inputResponses. One ask costs two units.
     */
    public function everyRoundCountsAgainstTheToolCallBudget(): void
    {
        $tester = $this->tester(interceptors: [new ToolCallBudgetInterceptor(budget: 1, cache: new FakeCache())]);

        $tester->callTool('order.delete', ['orderId' => '42']);
        $result = $this->answer($tester, 'order.delete', ['confirm' => ['action' => 'accept', 'content' => ['confirm' => true]]]);

        Assert::true($result['isError'] ?? false);
        Assert::string($result['content'][0]['text'] ?? '')->contains('budget of 1 per 3600 seconds is exhausted');
    }

    /**
     * @param array<string, array<string, mixed>> $answers
     *
     * @return array<array-key, mixed>
     */
    private function answer(McpTester $tester, string $tool, array $answers, ?string $requestState = null): array
    {
        $params = ['name' => $tool, 'arguments' => ['orderId' => '42'], 'inputResponses' => $answers];

        if ($requestState !== null) {
            $params['requestState'] = $requestState;
        }

        return $tester->request('tools/call', $params);
    }

    /**
     * @param list<ToolCallInterceptorInterface> $interceptors
     */
    private function tester(?ElicitingTool $tool = null, string $requestStateKey = '', array $interceptors = []): McpTester
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([ElicitingTool::class => $tool ?? new ElicitingTool()]),
            sessionStore: new InMemorySessionStore(),
            requestStateKey: $requestStateKey,
        ))->create([ElicitingTool::class], [], $interceptors);

        return new McpTester(
            server: $server,
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
            protocolVersion: ProtocolVersion::V2026_07_28,
            capabilities: new ClientCapabilities(elicitation: true),
        );
    }
}
