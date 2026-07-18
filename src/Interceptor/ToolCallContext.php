<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Mcp\Server\Session\SessionInterface;

/**
 * Everything an interceptor may inspect about one tools/call: the tool name,
 * the arguments as sent by the client (SDK-internal keys stripped), the
 * MCP session carrying initialize-handshake data, and the client identity
 * resolved by {@see \Rasuvaeff\Yii3Mcp\SharedSecretMiddleware} (never the
 * raw secret).
 *
 * @api
 */
final readonly class ToolCallContext
{
    /**
     * @param array<string, mixed> $arguments
     * @param ?string $clientId identity from the endpoint secret; null when the transport carries none (e.g. stdio)
     */
    public function __construct(
        public string $toolName,
        public array $arguments,
        public ?SessionInterface $session = null,
        public ?string $clientId = null,
    ) {}

    /**
     * Client identity from the initialize handshake (name, version, …);
     * empty before initialize or without a session.
     *
     * @return array<array-key, mixed>
     */
    public function getClientInfo(): array
    {
        /** @var mixed $info */
        $info = $this->session?->get('client_info');

        return is_array($info) ? $info : [];
    }
}
