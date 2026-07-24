<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

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
     */
    public function __construct(
        public string $uri,
        public array $variables = [],
        public ?string $uriTemplate = null,
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
