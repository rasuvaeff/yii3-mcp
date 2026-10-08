<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Visibility;

use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\Interceptor\InterceptingReferenceHandler;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpErrorException;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\CompletionTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyListVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyPromptVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyResourceVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Visibility\FilteredCallToolHandler;
use Rasuvaeff\Yii3Mcp\Visibility\FilteredCompletionCompleteHandler;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

/**
 * Visibility is only a boundary if a hidden capability cannot be told apart
 * from a missing one. Compared at the level of the whole JSON-RPC error —
 * code, message and data — because SDK 0.8 moved not-found from -32002 to
 * -32602 for some methods: a decorator still answering -32002 kept the same
 * text and became an existence oracle that a message-only check could not see.
 */
#[Test]
#[Covers(FilteredCallToolHandler::class)]
#[Covers(FilteredCompletionCompleteHandler::class)]
#[Covers(InterceptingReferenceHandler::class)]
final class HiddenIsMissingTest
{
    /**
     * @param array<string, mixed> $hiddenParams
     * @param array<string, mixed> $missingParams
     */
    #[DataProvider('capabilityProvider')]
    public function hiddenAnswersExactlyLikeMissing(
        string $method,
        array $hiddenParams,
        array $missingParams,
        string $hiddenName,
        string $missingName,
    ): void {
        $tester = $this->tester();

        $hidden = $this->error($tester, $method, $hiddenParams);
        $missing = $this->error($tester, $method, $missingParams);

        Assert::same(str_replace($hiddenName, $missingName, $hidden), $missing);
    }

    public static function capabilityProvider(): iterable
    {
        yield 'tools/call' => [
            'tools/call',
            ['name' => 'greet', 'arguments' => ['name' => 'Yii']],
            ['name' => 'no-such-tool', 'arguments' => ['name' => 'Yii']],
            'greet',
            'no-such-tool',
        ];
        // invalid arguments must not reveal the hidden tool's input schema
        yield 'tools/call with invalid arguments' => [
            'tools/call',
            ['name' => 'greet', 'arguments' => ['unexpected' => 1]],
            ['name' => 'no-such-tool', 'arguments' => ['unexpected' => 1]],
            'greet',
            'no-such-tool',
        ];
        yield 'prompts/get' => [
            'prompts/get',
            ['name' => 'greeting-style'],
            ['name' => 'no-such-prompt'],
            'greeting-style',
            'no-such-prompt',
        ];
        yield 'resources/read static' => [
            'resources/read',
            ['uri' => 'app://status'],
            ['uri' => 'app://no-such-resource'],
            'app://status',
            'app://no-such-resource',
        ];
        yield 'resources/read template' => [
            'resources/read',
            ['uri' => 'app://users/42'],
            ['uri' => 'app://no-such-template/42'],
            'app://users/42',
            'app://no-such-template/42',
        ];
        yield 'completion/complete prompt' => [
            'completion/complete',
            ['ref' => ['type' => 'ref/prompt', 'name' => 'secret-review'], 'argument' => ['name' => 'target', 'value' => 'x']],
            ['ref' => ['type' => 'ref/prompt', 'name' => 'no-such-prompt'], 'argument' => ['name' => 'target', 'value' => 'x']],
            'secret-review',
            'no-such-prompt',
        ];
        yield 'completion/complete resource template' => [
            'completion/complete',
            ['ref' => ['type' => 'ref/resource', 'uri' => 'app://reports/emea'], 'argument' => ['name' => 'region', 'value' => 'e']],
            ['ref' => ['type' => 'ref/resource', 'uri' => 'app://no-such-template/emea'], 'argument' => ['name' => 'region', 'value' => 'e']],
            'app://reports/emea',
            'app://no-such-template/emea',
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function error(McpTester $tester, string $method, array $params): string
    {
        try {
            $tester->request($method, $params);
        } catch (McpErrorException $e) {
            return json_encode([$e->errorCode, $e->errorMessage, $e->errorData], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return 'answered';
    }

    private function tester(): McpTester
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([
                GreetingTool::class => new GreetingTool(prefix: 'Hello'),
                CompletionTool::class => new CompletionTool(),
            ]),
            sessionStore: new InMemorySessionStore(),
            name: 'hidden-is-missing-suite',
            version: '1.0.0',
        ))->create(
            toolClasses: [GreetingTool::class, CompletionTool::class],
            toolVisibility: new DenyListVisibility(hidden: ['greet']),
            promptVisibility: new DenyPromptVisibility(hidden: ['greeting-style', 'secret-review']),
            resourceVisibility: new DenyResourceVisibility(
                hiddenUris: ['app://status'],
                hiddenTemplates: ['app://users/{id}', 'app://reports/{region}'],
            ),
        );

        return new McpTester($server, $factory, $factory, $factory);
    }
}
