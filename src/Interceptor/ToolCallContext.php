<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Mcp\Schema\Implementation;
use Mcp\Server\RequestContext;
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
     * @param RequestContext|null $requestContext the SDK's request scope: getClientGateway() to ask the
     *                                           user (elicit) or notify, getTraceContext() for the
     *                                           caller's W3C trace; null outside a server request
     */
    public function __construct(
        public string $toolName,
        public array $arguments,
        public ?SessionInterface $session = null,
        public ?string $clientId = null,
        public ?RequestContext $requestContext = null,
    ) {}

    /**
     * How the client named itself — from `initialize` in the handshake era,
     * from the request's `_meta` in the modern (2026-07-28) era; null when
     * it did not, or without a session.
     */
    public function clientInfo(): ?Implementation
    {
        return ClientInfoResolver::fromSession($this->session);
    }
}
