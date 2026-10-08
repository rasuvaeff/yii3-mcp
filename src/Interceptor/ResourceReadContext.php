<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Mcp\Schema\Implementation;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * Everything an interceptor may inspect about one resources/read: the
 * requested URI, the variables extracted from a resource template (empty
 * for a static resource), the template the URI matched (null for a static
 * resource), the MCP session and the client identity resolved by
 * {@see \Rasuvaeff\Yii3Mcp\SharedSecretMiddleware} (never the raw secret).
 *
 * @api
 */
final readonly class ResourceReadContext
{
    /**
     * @param array<string, mixed> $variables RFC 6570 variables extracted from the template URI
     * @param ?string $uriTemplate the matched template (e.g. `note://{id}`); null for a static resource
     * @param ?string $clientId identity from the endpoint secret; null when the transport carries none (e.g. stdio)
     * @param RequestContext|null $requestContext the SDK's request scope: getClientGateway() to ask the
     *                                           user (elicit) or notify, getTraceContext() for the
     *                                           caller's W3C trace; null outside a server request
     */
    public function __construct(
        public string $uri,
        public array $variables = [],
        public ?string $uriTemplate = null,
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
