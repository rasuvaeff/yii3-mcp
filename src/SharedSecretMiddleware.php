<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fail-closed shared-secret guard for the MCP endpoint: requests without the
 * expected header value are rejected with 401. An empty (unconfigured)
 * secret rejects every request with a clear 503 explanation — an
 * unprotected endpoint must be an explicit decision (network ACL), never a
 * silent default.
 *
 * @api
 */
final readonly class SharedSecretMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $secret,
        private ResponseFactoryInterface $responseFactory,
        private string $headerName = 'X-Mcp-Secret',
    ) {
        if ($this->headerName === '') {
            throw new InvalidArgumentException('Secret header name must not be empty');
        }
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->secret === '') {
            $response = $this->responseFactory->createResponse(503)
                ->withHeader('Content-Type', 'application/json');
            $response->getBody()->write(json_encode([
                'error' => 'MCP endpoint is not configured: the shared secret is empty. Set the "endpoint_secret" param (env MCP_SECRET) or protect the endpoint with a network ACL instead of this middleware.',
            ], JSON_THROW_ON_ERROR));

            return $response;
        }

        if (!hash_equals($this->secret, $request->getHeaderLine($this->headerName))) {
            $response = $this->responseFactory->createResponse(401)
                ->withHeader('Content-Type', 'application/json');
            $response->getBody()->write(json_encode([
                'error' => sprintf('Unauthorized: this is an MCP endpoint for MCP clients; requests must carry a valid "%s" header.', $this->headerName),
            ], JSON_THROW_ON_ERROR));

            return $response;
        }

        return $handler->handle($request);
    }
}
