<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Mcp\Server;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 endpoint serving the MCP Streamable HTTP transport. Route it behind
 * SharedSecretMiddleware (or a network ACL) — the endpoint is trusted-only.
 *
 * The SDK's DNS-rebinding protection allows only localhost by default;
 * production deployments behind a real domain list it in $allowedHosts.
 *
 * @api
 */
final readonly class McpAction implements RequestHandlerInterface
{
    private const array LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * @param list<string> $allowedHosts extra hosts accepted by the transport
     *                                   (the SDK's local defaults stay allowed)
     */
    public function __construct(
        private Server $server,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private array $allowedHosts = [],
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // upstream middleware (body parsers etc.) may have consumed the
        // stream already; the transport reads it again
        $body = $request->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $this->server->run(new StreamableHttpTransport(
            request: $request,
            responseFactory: $this->responseFactory,
            streamFactory: $this->streamFactory,
            middleware: $this->allowedHosts === [] ? null : $this->transportMiddleware(),
        ));
    }

    /**
     * The SDK default stack with the host allow-list widened — same
     * protections (CORS, DNS rebinding, protocol version), never fewer.
     *
     * @return list<\Psr\Http\Server\MiddlewareInterface>
     */
    private function transportMiddleware(): array
    {
        return [
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware(
                allowedHosts: [...self::LOCAL_HOSTS, ...$this->allowedHosts],
                responseFactory: $this->responseFactory,
                streamFactory: $this->streamFactory,
            ),
            new ProtocolVersionMiddleware(),
        ];
    }
}
