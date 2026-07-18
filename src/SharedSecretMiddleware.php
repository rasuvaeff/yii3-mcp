<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Mcp\Identity\SecretResolverInterface;
use Rasuvaeff\Yii3Mcp\Identity\StaticSecretResolver;

/**
 * Fail-closed shared-secret guard for the MCP endpoint: requests without a
 * valid header value are rejected with 401. An unconfigured middleware (empty
 * secret, no resolver) rejects every request with a clear 503 explanation —
 * an unprotected endpoint must be an explicit decision (network ACL), never a
 * silent default.
 *
 * A {@see SecretResolverInterface} enables several clients and secret
 * rotation; the resolved client id travels down the pipeline as the
 * {@see self::CLIENT_ID_ATTRIBUTE} request attribute (the raw secret does
 * not). The single `$secret` form stays as the backward-compatible adapter —
 * it behaves as one client named {@see self::DEFAULT_CLIENT_ID}.
 *
 * @api
 */
final readonly class SharedSecretMiddleware implements MiddlewareInterface
{
    /**
     * PSR-7 request attribute carrying the resolved client id.
     */
    public const string CLIENT_ID_ATTRIBUTE = 'rasuvaeff.yii3-mcp.client-id';

    /**
     * Client id assigned by the single-secret adapter.
     */
    public const string DEFAULT_CLIENT_ID = 'default';

    private ?SecretResolverInterface $resolver;

    public function __construct(
        string $secret,
        private ResponseFactoryInterface $responseFactory,
        private string $headerName = 'X-Mcp-Secret',
        ?SecretResolverInterface $resolver = null,
    ) {
        if ($this->headerName === '') {
            throw new InvalidArgumentException('Secret header name must not be empty');
        }
        if ($secret !== '' && $resolver instanceof SecretResolverInterface) {
            throw new InvalidArgumentException('Configure either a single secret or a secret resolver, not both');
        }

        // The single secret is one client named "default" — one code path,
        // identical constant-time comparison, identity always attributed.
        $this->resolver = $resolver ?? ($secret === '' ? null : new StaticSecretResolver([self::DEFAULT_CLIENT_ID => $secret]));
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->resolver instanceof SecretResolverInterface) {
            $response = $this->responseFactory->createResponse(503)
                ->withHeader('Content-Type', 'application/json');
            $response->getBody()->write(json_encode([
                'error' => 'MCP endpoint is not configured: the shared secret is empty. Set the "endpoint_secret" param (env MCP_SECRET), configure "client_secrets", or protect the endpoint with a network ACL instead of this middleware.',
            ], JSON_THROW_ON_ERROR));

            return $response;
        }

        $clientId = $this->resolver->resolve($request->getHeaderLine($this->headerName));

        if ($clientId === null) {
            $response = $this->responseFactory->createResponse(401)
                ->withHeader('Content-Type', 'application/json');
            $response->getBody()->write(json_encode([
                'error' => sprintf('Unauthorized: this is an MCP endpoint for MCP clients; requests must carry a valid "%s" header.', $this->headerName),
            ], JSON_THROW_ON_ERROR));

            return $response;
        }

        return $handler->handle($request->withAttribute(self::CLIENT_ID_ATTRIBUTE, $clientId));
    }
}
