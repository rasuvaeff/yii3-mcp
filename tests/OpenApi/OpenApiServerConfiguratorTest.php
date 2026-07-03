<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Yii3Mcp\McpAction;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\UnknownOperationException;
use Rasuvaeff\Yii3Mcp\OpenApi\HttpOperationExecutor;
use Rasuvaeff\Yii3Mcp\OpenApi\OpenApiServerConfigurator;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeHttpClient;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(OpenApiServerConfigurator::class)]
final class OpenApiServerConfiguratorTest
{
    public function bridgedOperationsAppearInToolsList(): void
    {
        $action = $this->action(new FakeHttpClient(), ['getBlogTags', 'getBlogTagBySlug']);
        $sessionId = $this->initialize($action)->getHeaderLine('Mcp-Session-Id');

        $response = $this->post($action, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sessionId);

        $tools = $this->decode($response)['result']['tools'];
        $byName = array_column($tools, null, 'name');

        Assert::same(array_keys($byName), ['getBlogTags', 'getBlogTagBySlug']);
        Assert::same($byName['getBlogTags']['description'], 'List blog tags');
        Assert::same($byName['getBlogTagBySlug']['inputSchema']['required'], ['slug']);
    }

    public function toolsCallExecutesHttpRequestAgainstUpstream(): void
    {
        $client = new FakeHttpClient(body: '[{"slug":"php"}]');
        $action = $this->action($client, ['getBlogTags']);
        $sessionId = $this->initialize($action)->getHeaderLine('Mcp-Session-Id');

        $response = $this->post($action, [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'getBlogTags', 'arguments' => ['locale' => 'ru']],
        ], $sessionId);

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/rest/blog-tags?locale=ru');

        $body = $this->decode($response);
        Assert::false(isset($body['error']));
        Assert::same(json_decode((string) $body['result']['content'][0]['text'], true), [['slug' => 'php']]);
    }

    public function upstreamFailureSurfacesAsToolError(): void
    {
        $action = $this->action(new FakeHttpClient(statusCode: 500, body: 'boom'), ['getBlogTags']);
        $sessionId = $this->initialize($action)->getHeaderLine('Mcp-Session-Id');

        $response = $this->post($action, [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'getBlogTags', 'arguments' => []],
        ], $sessionId);

        $body = $this->decode($response);
        $isToolError = ($body['result']['isError'] ?? false) === true || isset($body['error']);
        Assert::true($isToolError);
    }

    public function unknownAllowListedOperationFailsAtBuildTime(): void
    {
        Expect::exception(UnknownOperationException::class);

        $this->action(new FakeHttpClient(), ['nonExistentOperation']);
    }

    public function safeMethodsOnlyRejectsNonGetOperationsAtBuildTime(): void
    {
        $caught = null;

        try {
            $this->action(new FakeHttpClient(), ['createSubscriber'], safeMethodsOnly: true);
        } catch (UnknownOperationException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('safe_methods_only');
    }

    public function safeMethodsOnlyStillExposesGetOperations(): void
    {
        $action = $this->action(new FakeHttpClient(), ['getBlogTags'], safeMethodsOnly: true);
        $sessionId = $this->initialize($action)->getHeaderLine('Mcp-Session-Id');

        $response = $this->post($action, ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/list'], $sessionId);

        Assert::same(array_column($this->decode($response)['result']['tools'], 'name'), ['getBlogTags']);
    }

    public function emptyAllowListExposesNothing(): void
    {
        $action = $this->action(new FakeHttpClient(), []);
        $sessionId = $this->initialize($action)->getHeaderLine('Mcp-Session-Id');

        $response = $this->post($action, ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list'], $sessionId);

        Assert::same($this->decode($response)['result']['tools'] ?? [], []);
    }

    /**
     * @param list<string> $operations
     */
    private function action(FakeHttpClient $client, array $operations, bool $safeMethodsOnly = false): McpAction
    {
        $factory = new Psr17Factory();

        $configurator = new OpenApiServerConfigurator(
            spec: new SpecIndex(OpenApiFixture::spec()),
            executor: new HttpOperationExecutor(
                httpClient: $client,
                requestFactory: $factory,
                streamFactory: $factory,
                baseUrl: 'https://api.test',
            ),
            operations: $operations,
            safeMethodsOnly: $safeMethodsOnly,
        );

        $server = (new McpServerFactory(
            container: new SimpleContainer([]),
            sessionStore: new InMemorySessionStore(),
            name: 'bridge-test',
            version: '1.0.0',
        ))->create([], [$configurator]);

        return new McpAction(server: $server, responseFactory: $factory, streamFactory: $factory);
    }

    private function initialize(McpAction $action): ResponseInterface
    {
        return $this->post($action, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(McpAction $action, array $payload, string $sessionId = ''): ResponseInterface
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/mcp',
            headers: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
            ],
            body: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        if ($sessionId !== '') {
            $request = $request
                ->withHeader('Mcp-Session-Id', $sessionId)
                ->withHeader('MCP-Protocol-Version', '2025-06-18');
        }

        return $action->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();

        if (str_starts_with(trim($raw), 'event:') || str_starts_with(trim($raw), 'data:')) {
            preg_match('/^data: (.*)$/m', $raw, $matches);
            $raw = $matches[1] ?? '';
        }

        /** @var array<string, mixed> */
        return json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
    }
}
