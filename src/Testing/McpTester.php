<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Testing;

use Mcp\Client\Stateless\HeaderFactory;
use Mcp\Client\Stateless\RequestEnvelope;
use Mcp\Client\Stateless\ToolCatalog;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * In-process MCP client for testing application tools without HTTP or a
 * transport process: drives the same Streamable HTTP code path the real
 * endpoint uses and returns decoded results.
 *
 * By default it speaks the handshake era (initialize, session id); given a
 * modern revision (2026-07-28) it speaks the stateless era instead — no
 * initialize, every request carries its own `_meta` envelope and the
 * standard `Mcp-Method`/`Mcp-Name`/`Mcp-Param-*` headers, built by the SDK's
 * own client classes so the tester cannot drift from a conformant client.
 *
 * ```php
 * $tester = new McpTester($server, $requestFactory, $responseFactory, $streamFactory);
 * $tester->callTool('order.status', ['orderId' => '42']);
 *
 * $modern = new McpTester($server, $requestFactory, $responseFactory, $streamFactory, ProtocolVersion::V2026_07_28);
 * ```
 *
 * @api
 */
final class McpTester
{
    private const string CLIENT_NAME = 'mcp-tester';

    private const string CLIENT_VERSION = '1.0';

    private string $sessionId = '';

    private int $requestId = 0;

    private readonly ToolCatalog $toolCatalog;

    /**
     * @param ProtocolVersion|null $protocolVersion the revision to speak; null = the SDK's
     *                                              newest handshake revision
     * @param ClientCapabilities|null $capabilities what the tester claims to support — declare
     *                                              `elicitation` to test a tool that asks the user
     */
    public function __construct(
        private readonly Server $server,
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?ProtocolVersion $protocolVersion = null,
        private readonly ?ClientCapabilities $capabilities = null,
    ) {
        $this->toolCatalog = new ToolCatalog();
    }

    /**
     * Performs the initialize handshake; called implicitly by the other
     * methods when needed. The modern era has no handshake: there it asks
     * `server/discover` instead, which answers the same questions.
     *
     * @return array<array-key, mixed> the initialize (or server/discover) result
     */
    public function initialize(): array
    {
        if ($this->isModern()) {
            return $this->result($this->post(['jsonrpc' => '2.0', 'id' => ++$this->requestId, 'method' => 'server/discover']));
        }

        $response = $this->post([
            'jsonrpc' => '2.0',
            'id' => ++$this->requestId,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => $this->protocolVersion(),
                'capabilities' => $this->capabilities ?? new \stdClass(),
                'clientInfo' => ['name' => self::CLIENT_NAME, 'version' => self::CLIENT_VERSION],
            ],
        ]);

        $this->sessionId = $response->getHeaderLine('Mcp-Session-Id');
        $this->notify('notifications/initialized');

        return $this->result($response);
    }

    /**
     * @return list<array<array-key, mixed>> tool definitions as exposed by tools/list
     */
    public function listTools(): array
    {
        return $this->listAll(method: 'tools/list', key: 'tools');
    }

    /**
     * @return list<array<array-key, mixed>> resource definitions as exposed by resources/list
     */
    public function listResources(): array
    {
        return $this->listAll(method: 'resources/list', key: 'resources');
    }

    /**
     * @return list<array<array-key, mixed>> resource template definitions as exposed by resources/templates/list
     */
    public function listResourceTemplates(): array
    {
        return $this->listAll(method: 'resources/templates/list', key: 'resourceTemplates');
    }

    /**
     * @return list<array<array-key, mixed>> prompt definitions as exposed by prompts/list
     */
    public function listPrompts(): array
    {
        return $this->listAll(method: 'prompts/list', key: 'prompts');
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function listAll(string $method, string $key): array
    {
        $items = [];
        $cursor = null;
        $seenCursors = [];

        do {
            $result = $this->request($method, $cursor === null ? null : ['cursor' => $cursor]);

            /** @var mixed $item */
            foreach ($this->arrayOrEmpty($result[$key] ?? null) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            // the catalog derives the Mcp-Param-* headers a modern tools/call
            // must carry for the tool's declared header parameters
            if ($method === 'tools/list') {
                /** @var list<array<string, mixed>> $items */
                $this->toolCatalog->record($items);
            }

            /** @var mixed $nextCursor */
            $nextCursor = $result['nextCursor'] ?? null;
            $cursor = is_string($nextCursor) && $nextCursor !== '' ? $nextCursor : null;

            if ($cursor !== null && isset($seenCursors[$cursor])) {
                throw new RuntimeException(sprintf('MCP pagination for "%s" returned repeated cursor "%s"', $method, $cursor));
            }

            if ($cursor !== null) {
                $seenCursors[$cursor] = true;
            }
        } while ($cursor !== null);

        return $items;
    }

    /**
     * Calls a tool and returns the decoded result envelope
     * (content, isError, structuredContent, …).
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    public function callTool(string $name, array $arguments = []): array
    {
        return $this->request('tools/call', ['name' => $name, 'arguments' => $arguments === [] ? new \stdClass() : $arguments]);
    }

    /**
     * @return array<array-key, mixed> the resources/read result (contents list)
     */
    public function readResource(string $uri): array
    {
        return $this->request('resources/read', ['uri' => $uri]);
    }

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array<array-key, mixed> the JSON-RPC result
     */
    public function request(string $method, ?array $params = null): array
    {
        if ($this->sessionId === '' && !$this->isModern()) {
            $this->initialize();
        }

        $payload = ['jsonrpc' => '2.0', 'id' => ++$this->requestId, 'method' => $method];

        if ($params !== null) {
            $payload['params'] = $params;
        }

        return $this->result($this->post($payload));
    }

    /**
     * The revision the SDK itself advertises in `initialize` — read from the
     * SDK rather than hardcoded, so the tester never claims a protocol version
     * the server under test does not answer with (they disagreed once,
     * silently). A class constant cannot hold it: Psalm does not evaluate an
     * enum case's `->value` in a constant initializer and infers `mixed`.
     */
    private function protocolVersion(): string
    {
        return ($this->protocolVersion ?? MessageInterface::PROTOCOL_VERSION)->value;
    }

    private function isModern(): bool
    {
        return $this->protocolVersion instanceof ProtocolVersion && $this->protocolVersion->isModern();
    }

    private function notify(string $method): void
    {
        $this->post(['jsonrpc' => '2.0', 'method' => $method]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(array $payload): ResponseInterface
    {
        $headers = [];

        if ($this->protocolVersion instanceof ProtocolVersion && $this->isModern()) {
            $payload = (new RequestEnvelope(
                $this->protocolVersion,
                $this->capabilities ?? new ClientCapabilities(),
                new Implementation(name: self::CLIENT_NAME, version: self::CLIENT_VERSION),
            ))->stamp($payload);
            /** @var array<string, mixed> $serialized the message exactly as it goes on the wire */
            $serialized = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), associative: true, flags: JSON_THROW_ON_ERROR);
            $headers = (new HeaderFactory($this->toolCatalog))->forMessage($serialized, $this->protocolVersion);
        }

        $request = $this->requestFactory
            ->createServerRequest('POST', '/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));

        if ($this->sessionId !== '') {
            $request = $request
                ->withHeader('Mcp-Session-Id', $this->sessionId)
                ->withHeader('MCP-Protocol-Version', $this->protocolVersion());
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->server->run(new StreamableHttpTransport(
            request: $request,
            responseFactory: $this->responseFactory,
            streamFactory: $this->streamFactory,
        ));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function result(ResponseInterface $response): array
    {
        // Streamable HTTP may frame the JSON-RPC message as an SSE event
        $raw = SseFrame::payload((string) $response->getBody());

        if ($raw === '') {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            /** @var mixed $code */
            $code = $decoded['error']['code'] ?? null;

            throw new McpErrorException(
                errorCode: is_int($code) ? $code : 0,
                errorMessage: $this->stringOr($decoded['error']['message'] ?? null, 'unknown error'),
                errorData: $decoded['error']['data'] ?? null,
            );
        }

        return $this->arrayOrEmpty($decoded['result'] ?? null);
    }

    private function stringOr(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
