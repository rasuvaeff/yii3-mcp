<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OAuth;

use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;

/**
 * OAuth 2.1 resource-server guard for the MCP endpoint, the alternative to
 * {@see SharedSecretMiddleware}: route `[BearerTokenMiddleware, McpAction]`.
 *
 * Token validation, the `WWW-Authenticate` challenges (401, 403
 * `insufficient_scope`, 503 when the signing keys cannot be fetched) and the
 * per-method/per-tool scopes are the SDK's own {@see AuthorizationMiddleware}.
 * This class only adds the step the rest of the package needs: the token's
 * identity becomes the client id ({@see SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE}),
 * so session ownership, visibility, the tool-call budget and the result cache
 * key work exactly as with shared secrets. The validated token stays on the
 * request, so the SDK binds the session to its principal and a handler reads
 * it through `RequestContext::getAccessToken()`.
 *
 * A token without the configured identity claim is refused with 401
 * `invalid_token` — never served anonymously.
 *
 * @api
 */
final readonly class BearerTokenMiddleware implements MiddlewareInterface
{
    /** Issuer, client and subject together (the SDK's AccessToken::getPrincipal()) */
    public const string IDENTITY_PRINCIPAL = 'principal';

    /** The `client_id` claim (RFC 9068), falling back to `azp` */
    public const string IDENTITY_CLIENT_ID = 'client_id';

    /** The `sub` claim */
    public const string IDENTITY_SUBJECT = 'subject';

    /**
     * @param string $identity which part of the token becomes the client id:
     *                         one of the IDENTITY_* constants
     */
    public function __construct(
        private AuthorizationMiddleware $authorization,
        private ResponseFactoryInterface $responseFactory,
        private string $identity = self::IDENTITY_PRINCIPAL,
    ) {
        if (!in_array($identity, [self::IDENTITY_PRINCIPAL, self::IDENTITY_CLIENT_ID, self::IDENTITY_SUBJECT], strict: true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported OAuth identity "%s"; supported: %s, %s, %s',
                $identity,
                self::IDENTITY_PRINCIPAL,
                self::IDENTITY_CLIENT_ID,
                self::IDENTITY_SUBJECT,
            ));
        }
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->authorization->process(
            $request,
            new AuthorizedRequestHandler($handler, $this->responseFactory, $this->identity),
        );
    }
}
