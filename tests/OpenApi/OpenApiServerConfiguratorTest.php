<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use InvalidArgumentException;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Yii3Mcp\McpAction;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\UnknownOperationException;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\UnsafeOperationException;
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

    public function argumentLessOperationServesPropertiesAsJsonObject(): void
    {
        $action = $this->action(new FakeHttpClient(), ['getSitemap']);
        $sessionId = $this->initialize($action)->getHeaderLine('Mcp-Session-Id');

        $response = $this->post($action, ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/list'], $sessionId);

        // asserted on the wire, not on the decoded body: json_decode with
        // associative: true turns {} back into [] and hides the difference
        // that makes clients reject the whole tools/list
        Assert::string($this->raw($response))->contains('"properties":{}');

        /** @var \stdClass $body */
        $body = json_decode($this->raw($response), flags: JSON_THROW_ON_ERROR);
        Assert::instanceOf($body->result->tools[0]->inputSchema->properties, \stdClass::class);
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

    public function objectResponseAdvertisesOutputSchemaInToolsList(): void
    {
        $action = $this->action(new FakeHttpClient(), ['getBlogTags', 'getBlogTagBySlug']);
        $sessionId = $this->initialize($action)->getHeaderLine('Mcp-Session-Id');

        $response = $this->post($action, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list'], $sessionId);

        $byName = array_column($this->decode($response)['result']['tools'], null, 'name');

        Assert::same($byName['getBlogTagBySlug']['outputSchema']['type'], 'object');
        Assert::same($byName['getBlogTagBySlug']['outputSchema']['required'], ['slug']);
        // array response: no outputSchema advertised (MCP requires object)
        Assert::false(isset($byName['getBlogTags']['outputSchema']));
    }

    public function objectResponsePayloadArrivesAsStructuredContent(): void
    {
        $client = new FakeHttpClient(body: '{"slug":"php","title":"PHP"}');
        $action = $this->action($client, ['getBlogTagBySlug']);
        $sessionId = $this->initialize($action)->getHeaderLine('Mcp-Session-Id');

        $response = $this->post($action, [
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/call',
            'params' => ['name' => 'getBlogTagBySlug', 'arguments' => ['slug' => 'php']],
        ], $sessionId);

        $body = $this->decode($response);

        Assert::false(isset($body['error']));
        Assert::same($body['result']['structuredContent'], ['slug' => 'php', 'title' => 'PHP']);
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
        } catch (UnsafeOperationException $caught) {
        }

        // the dedicated type: the operation IS in the document, so catching
        // UnknownOperationException for wiring mistakes must not swallow it
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

    public function renamedToolIsServedUnderTheNewNameOnly(): void
    {
        $client = new FakeHttpClient(body: '[{"slug":"php"}]');
        $action = $this->action($client, ['getBlogTags'], toolNames: ['getBlogTags' => 'blog_tags_list']);
        $sessionId = $this->initialize($action)->getHeaderLine('Mcp-Session-Id');

        $listResponse = $this->post($action, ['jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/list'], $sessionId);
        Assert::same(array_column($this->decode($listResponse)['result']['tools'], 'name'), ['blog_tags_list']);

        $callResponse = $this->post($action, [
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'tools/call',
            'params' => ['name' => 'blog_tags_list', 'arguments' => []],
        ], $sessionId);
        Assert::false(isset($this->decode($callResponse)['error']));

        $oldNameResponse = $this->post($action, [
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => 'tools/call',
            'params' => ['name' => 'getBlogTags', 'arguments' => []],
        ], $sessionId);
        $body = $this->decode($oldNameResponse);
        Assert::true(isset($body['error']) || ($body['result']['isError'] ?? false) === true);
    }

    public function toolNamesWithUnknownOperationIdFailsAtBuildTime(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->action(new FakeHttpClient(), ['getBlogTags'], toolNames: ['nonExistentOperation' => 'x']);
    }

    public function toolNamesRenameToInvalidNameFailsAtBuildTime(): void
    {
        $caught = null;

        try {
            $this->action(new FakeHttpClient(), ['getBlogTags'], toolNames: ['getBlogTags' => 'get blog tags']);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('get blog tags');
    }

    public function toolNamesCollisionFailsAtBuildTime(): void
    {
        $caught = null;

        try {
            $this->action(
                new FakeHttpClient(),
                ['getBlogTags', 'getBlogTagBySlug'],
                toolNames: ['getBlogTagBySlug' => 'getBlogTags'],
            );
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('getBlogTags');
        Assert::string($caught->getMessage())->contains('getBlogTagBySlug');
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
     * @param array<string, string> $toolNames
     */
    private function action(FakeHttpClient $client, array $operations, bool $safeMethodsOnly = false, array $toolNames = []): McpAction
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
            toolNames: $toolNames,
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
        /** @var array<string, mixed> */
        return json_decode($this->raw($response), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    private function raw(ResponseInterface $response): string
    {
        $raw = (string) $response->getBody();

        if (str_starts_with(trim($raw), 'event:') || str_starts_with(trim($raw), 'data:')) {
            preg_match('/^data: (.*)$/m', $raw, $matches);
            $raw = $matches[1] ?? '';
        }

        return $raw;
    }
}
