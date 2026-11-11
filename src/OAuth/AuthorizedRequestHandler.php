<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OAuth;

use Mcp\Server\Authorization\AccessToken;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;

/**
 * The handler {@see BearerTokenMiddleware} puts behind the SDK's
 * AuthorizationMiddleware: it runs only for a request whose token passed,
 * turns the token's identity into the client id and hands the request on.
 *
 * @internal
 */
final readonly class AuthorizedRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestHandlerInterface $next,
        private ResponseFactoryInterface $responseFactory,
        private string $identity,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var mixed $token */
        $token = $request->getAttribute(AccessToken::class);
        $clientId = $token instanceof AccessToken ? $this->clientId($token) : null;

        // fail closed: the SDK always sets the token on an authorized request,
        // and a token without the configured claim has no identity to serve
        if ($clientId === null || $clientId === '') {
            return $this->responseFactory->createResponse(401)
                ->withHeader('WWW-Authenticate', 'Bearer error="invalid_token", error_description="The access token does not identify the client."');
        }

        return $this->next->handle($request->withAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE, $clientId));
    }

    private function clientId(AccessToken $token): ?string
    {
        return match ($this->identity) {
            BearerTokenMiddleware::IDENTITY_CLIENT_ID => $token->getClientId(),
            BearerTokenMiddleware::IDENTITY_SUBJECT => $token->getSubject(),
            default => $token->getPrincipal(),
        };
    }
}
