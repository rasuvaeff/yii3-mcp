<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Stateless\OriginatingRequestBinder;
use Mcp\Server\Stateless\RequestStateBinderInterface;
use Rasuvaeff\Yii3Mcp\Identity\ClientIdentityContext;

/**
 * Binds a multi round-trip `requestState` to the request it was minted for
 * (the SDK's {@see OriginatingRequestBinder}: method, tool/prompt/resource,
 * a digest of the arguments and the OAuth principal) AND to the package's
 * resolved client id.
 *
 * The SDK binder knows only an OAuth token; behind
 * {@see SharedSecretMiddleware} there is none, so a state minted for one
 * client would verify when another client replays it with the same
 * arguments. The client id comes from the identity holder McpAction arms for
 * the request — the stateless era has no session to read it from.
 *
 * @internal
 */
final readonly class ClientBoundRequestStateBinder implements RequestStateBinderInterface
{
    public function __construct(
        private RequestStateBinderInterface $inner = new OriginatingRequestBinder(),
    ) {}

    #[\Override]
    public function bind(Request $request, ?AccessToken $accessToken): string
    {
        return json_encode(
            ['request' => $this->inner->bind($request, $accessToken), 'client' => ClientIdentityContext::current()],
            JSON_THROW_ON_ERROR,
        );
    }
}
