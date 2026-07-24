<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\Identity\ClientIdentityContext;
use Rasuvaeff\Yii3Mcp\Interceptor\InterceptingReferenceHandler;
use Rasuvaeff\Yii3Mcp\Interceptor\PromptGetInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\ResourceReadInterceptorInterface;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyPromptVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyResourceVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingInterceptor;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingPromptInterceptor;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingResourceInterceptor;
use Rasuvaeff\Yii3Mcp\Visibility\PromptVisibilityInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ResourceVisibilityInterface;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

/**
 * prompts/get and resources/read interceptor chains + fail-closed
 * prompt/resource visibility, end-to-end through the MCP protocol.
 */
#[Test]
#[Covers(InterceptingReferenceHandler::class)]
#[Covers(McpServerFactory::class)]
final class CapabilityHooksTest
{
    public function promptInterceptorWrapsPromptsGet(): void
    {
        $recording = new RecordingPromptInterceptor();

        $result = $this->tester(promptInterceptors: [$recording])
            ->request('prompts/get', ['name' => 'greeting-style']);

        Assert::string($result['messages'][0]['content']['text'] ?? '')->contains('warmly');
        Assert::same($recording->entries, [
            'prompt-interceptor:before:greeting-style',
            'prompt-interceptor:after:greeting-style',
        ]);
    }

    public function promptInterceptorsRunInConfiguredOrderFirstOutermost(): void
    {
        $outer = new RecordingPromptInterceptor('outer');
        $inner = new RecordingPromptInterceptor('inner', timeline: $outer);

        $this->tester(promptInterceptors: [$outer, $inner])
            ->request('prompts/get', ['name' => 'greeting-style']);

        Assert::same($outer->entries, [
            'outer:before:greeting-style',
            'inner:before:greeting-style',
            'inner:after:greeting-style',
            'outer:after:greeting-style',
        ]);
    }

    public function promptContextCarriesNameSessionAndClientInfo(): void
    {
        $recording = new RecordingPromptInterceptor();

        $this->tester(promptInterceptors: [$recording])
            ->request('prompts/get', ['name' => 'greeting-style']);

        $context = $recording->lastContext;

        Assert::notNull($context);
        Assert::same($context->promptName, 'greeting-style');
        Assert::same($context->arguments, []);
        Assert::same($context->getClientInfo()['name'] ?? null, 'mcp-tester');
        Assert::notNull($context->session);
    }

    public function promptRejectionSurfacesAsErrorWithTheMessage(): void
    {
        $tester = $this->tester(promptInterceptors: [new RecordingPromptInterceptor(rejectWith: 'prompts are closed')]);

        try {
            $tester->request('prompts/get', ['name' => 'greeting-style']);

            Assert::true(false);
        } catch (RuntimeException $exception) {
            Assert::string($exception->getMessage())->contains('prompts are closed');
        }
    }

    public function toolAndPromptChainsAreIsolated(): void
    {
        $tool = new RecordingInterceptor();
        $prompt = new RecordingPromptInterceptor();
        $tester = $this->tester(interceptors: [$tool], promptInterceptors: [$prompt]);

        $tester->request('prompts/get', ['name' => 'greeting-style']);

        Assert::same($tool->entries, []);

        $tester->callTool('greet', ['name' => 'Yii']);

        Assert::same($prompt->entries, [
            'prompt-interceptor:before:greeting-style',
            'prompt-interceptor:after:greeting-style',
        ]);
    }

    public function resourceInterceptorWrapsStaticRead(): void
    {
        $recording = new RecordingResourceInterceptor();

        $result = $this->tester(resourceInterceptors: [$recording])->readResource('app://status');

        Assert::same($result['contents'][0]['text'] ?? null, 'ok');
        Assert::same($recording->entries, [
            'resource-interceptor:before:app://status',
            'resource-interceptor:after:app://status',
        ]);

        $context = $recording->lastContext;

        Assert::notNull($context);
        Assert::same($context->uri, 'app://status');
        Assert::same($context->variables, []);
        Assert::null($context->uriTemplate);
    }

    public function templateReadContextCarriesVariablesAndTemplate(): void
    {
        $recording = new RecordingResourceInterceptor();

        $this->tester(resourceInterceptors: [$recording])->readResource('app://users/42');

        $context = $recording->lastContext;

        Assert::notNull($context);
        Assert::same($context->uri, 'app://users/42');
        Assert::same($context->variables, ['id' => '42']);
        Assert::same($context->uriTemplate, 'app://users/{id}');
        Assert::same($context->getClientInfo()['name'] ?? null, 'mcp-tester');
    }

    public function resourceInterceptorsRunInConfiguredOrderFirstOutermost(): void
    {
        $outer = new RecordingResourceInterceptor('outer');
        $inner = new RecordingResourceInterceptor('inner', timeline: $outer);

        $this->tester(resourceInterceptors: [$outer, $inner])->readResource('app://status');

        Assert::same($outer->entries, [
            'outer:before:app://status',
            'inner:before:app://status',
            'inner:after:app://status',
            'outer:after:app://status',
        ]);
    }

    public function resourceRejectionSurfacesAsErrorWithTheMessage(): void
    {
        $tester = $this->tester(resourceInterceptors: [new RecordingResourceInterceptor(rejectWith: 'reads are closed')]);

        try {
            $tester->readResource('app://status');

            Assert::true(false);
        } catch (RuntimeException $exception) {
            Assert::string($exception->getMessage())->contains('reads are closed');
        }
    }

    public function armedClientIdentityReachesPromptAndResourceContexts(): void
    {
        $prompt = new RecordingPromptInterceptor();
        $resource = new RecordingResourceInterceptor();
        $tester = $this->tester(promptInterceptors: [$prompt], resourceInterceptors: [$resource]);

        ClientIdentityContext::arm('claude');

        try {
            $tester->request('prompts/get', ['name' => 'greeting-style']);
            $tester->readResource('app://status');
        } finally {
            ClientIdentityContext::disarm();
        }

        Assert::same($prompt->lastContext?->clientId, 'claude');
        Assert::same($resource->lastContext?->clientId, 'claude');
    }

    public function hiddenPromptIsReportedAsNotFound(): void
    {
        $tester = $this->tester(promptVisibility: new DenyPromptVisibility(hidden: ['greeting-style']));

        try {
            $tester->request('prompts/get', ['name' => 'greeting-style']);

            Assert::true(false);
        } catch (RuntimeException $exception) {
            Assert::string($exception->getMessage())->contains('not found');
        }
    }

    public function hiddenPromptNeverReachesTheInterceptors(): void
    {
        $recording = new RecordingPromptInterceptor();
        $tester = $this->tester(
            promptInterceptors: [$recording],
            promptVisibility: new DenyPromptVisibility(hidden: ['greeting-style']),
        );

        try {
            $tester->request('prompts/get', ['name' => 'greeting-style']);
        } catch (RuntimeException) {
        }

        Assert::same($recording->entries, []);
    }

    public function visiblePromptPassesTheVisibilityCheck(): void
    {
        $result = $this->tester(promptVisibility: new DenyPromptVisibility(hidden: ['other-prompt']))
            ->request('prompts/get', ['name' => 'greeting-style']);

        Assert::string($result['messages'][0]['content']['text'] ?? '')->contains('warmly');
    }

    public function hiddenResourceIsReportedAsNotFound(): void
    {
        $tester = $this->tester(resourceVisibility: new DenyResourceVisibility(hiddenUris: ['app://status']));

        try {
            $tester->readResource('app://status');

            Assert::true(false);
        } catch (RuntimeException $exception) {
            Assert::string($exception->getMessage())->contains('not found');
        }
    }

    public function uriMatchingAHiddenTemplateIsReportedAsNotFound(): void
    {
        $tester = $this->tester(resourceVisibility: new DenyResourceVisibility(hiddenTemplates: ['app://users/{id}']));

        try {
            $tester->readResource('app://users/42');

            Assert::true(false);
        } catch (RuntimeException $exception) {
            Assert::string($exception->getMessage())->contains('not found');
        }
    }

    public function visibleResourceAndTemplateStayReadable(): void
    {
        $tester = $this->tester(resourceVisibility: new DenyResourceVisibility(
            hiddenUris: ['app://other'],
            hiddenTemplates: ['app://other/{id}'],
        ));

        Assert::same($tester->readResource('app://status')['contents'][0]['text'] ?? null, 'ok');
        Assert::string($tester->readResource('app://users/42')['contents'][0]['text'] ?? '')->contains('42');
    }

    public function everyChainRunsWhenEverythingIsConfiguredAtOnce(): void
    {
        $tool = new RecordingInterceptor();
        $prompt = new RecordingPromptInterceptor();
        $resource = new RecordingResourceInterceptor();
        $tester = $this->tester(
            interceptors: [$tool],
            promptInterceptors: [$prompt],
            resourceInterceptors: [$resource],
            promptVisibility: new DenyPromptVisibility(),
            resourceVisibility: new DenyResourceVisibility(),
        );

        $tester->callTool('greet', ['name' => 'Yii']);
        $tester->request('prompts/get', ['name' => 'greeting-style']);
        $tester->readResource('app://status');

        Assert::same($tool->entries, ['interceptor:before:greet', 'interceptor:after:greet']);
        Assert::same($prompt->entries, [
            'prompt-interceptor:before:greeting-style',
            'prompt-interceptor:after:greeting-style',
        ]);
        Assert::same($resource->entries, [
            'resource-interceptor:before:app://status',
            'resource-interceptor:after:app://status',
        ]);
    }

    public function withoutHooksPromptsAndResourcesBehaveUntouched(): void
    {
        $tester = $this->tester();

        Assert::same($tester->readResource('app://status')['contents'][0]['text'] ?? null, 'ok');

        $result = $tester->request('prompts/get', ['name' => 'greeting-style']);

        Assert::string($result['messages'][0]['content']['text'] ?? '')->contains('warmly');
    }

    /**
     * @param list<\Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface> $interceptors
     * @param list<PromptGetInterceptorInterface> $promptInterceptors
     * @param list<ResourceReadInterceptorInterface> $resourceInterceptors
     */
    private function tester(
        array $interceptors = [],
        array $promptInterceptors = [],
        array $resourceInterceptors = [],
        ?PromptVisibilityInterface $promptVisibility = null,
        ?ResourceVisibilityInterface $resourceVisibility = null,
    ): McpTester {
        $factory = new Psr17Factory();

        return new McpTester(
            server: $this->server($interceptors, $promptInterceptors, $resourceInterceptors, $promptVisibility, $resourceVisibility),
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    /**
     * @param list<\Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface> $interceptors
     * @param list<PromptGetInterceptorInterface> $promptInterceptors
     * @param list<ResourceReadInterceptorInterface> $resourceInterceptors
     */
    private function server(
        array $interceptors,
        array $promptInterceptors,
        array $resourceInterceptors,
        ?PromptVisibilityInterface $promptVisibility,
        ?ResourceVisibilityInterface $resourceVisibility,
    ): Server {
        return (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
            name: 'capability-hooks-suite',
            version: '1.0.0',
        ))->create(
            [GreetingTool::class],
            [],
            $interceptors,
            null,
            $promptInterceptors,
            $resourceInterceptors,
            $promptVisibility,
            $resourceVisibility,
        );
    }
}
