<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Testing;

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
 * endpoint uses (initialize handshake, session id, JSON-RPC framing) and
 * returns decoded results.
 *
 * ```php
 * $tester = new McpTester($server, $requestFactory, $responseFactory, $streamFactory);
 * $tester->callTool('order.status', ['orderId' => '42']);
 * ```
 *
 * @api
 */
final class McpTester
{
    private const string PROTOCOL_VERSION = '2025-06-18';

    private string $sessionId = '';

    private int $requestId = 0;

    public function __construct(
        private readonly Server $server,
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * Performs the initialize handshake; called implicitly by the other
     * methods when needed.
     *
     * @return array<array-key, mixed> the initialize result (serverInfo, capabilities, …)
     */
    public function initialize(): array
    {
        $response = $this->post([
            'jsonrpc' => '2.0',
            'id' => ++$this->requestId,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => [],
                'clientInfo' => ['name' => 'mcp-tester', 'version' => '1.0'],
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
        $tools = [];
        /** @var mixed $tool */
        foreach ($this->arrayOrEmpty($this->request('tools/list')['tools'] ?? null) as $tool) {
            if (is_array($tool)) {
                $tools[] = $tool;
            }
        }

        return $tools;
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
        if ($this->sessionId === '') {
            $this->initialize();
        }

        $payload = ['jsonrpc' => '2.0', 'id' => ++$this->requestId, 'method' => $method];

        if ($params !== null) {
            $payload['params'] = $params;
        }

        return $this->result($this->post($payload));
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
        $request = $this->requestFactory
            ->createServerRequest('POST', '/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));

        if ($this->sessionId !== '') {
            $request = $request
                ->withHeader('Mcp-Session-Id', $this->sessionId)
                ->withHeader('MCP-Protocol-Version', self::PROTOCOL_VERSION);
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
        $raw = (string) $response->getBody();

        // Streamable HTTP may frame the JSON-RPC message as an SSE event
        if (str_starts_with(trim($raw), 'event:') || str_starts_with(trim($raw), 'data:')) {
            preg_match('/^data: (.*)$/m', $raw, $matches);
            $raw = $matches[1] ?? '';
        }

        if ($raw === '') {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            throw new RuntimeException(sprintf(
                'MCP error: %s',
                $this->stringOr($decoded['error']['message'] ?? null, 'unknown error'),
            ));
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
