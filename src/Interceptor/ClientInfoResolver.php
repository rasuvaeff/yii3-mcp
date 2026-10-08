<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Mcp\Schema\Implementation;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Stateless\RequestMeta;

/**
 * Where the client named itself depends on the protocol era: the handshake
 * era stores `initialize`'s clientInfo on the session, the modern era
 * (2026-07-28) carries it in every request's `_meta`, which the SDK places on
 * the per-request session as {@see RequestMeta} — the same lookup the SDK's
 * own RequestContext::getClientCapabilities() does.
 *
 * @internal
 */
final readonly class ClientInfoResolver
{
    public static function fromSession(?SessionInterface $session): ?Implementation
    {
        if (!$session instanceof SessionInterface) {
            return null;
        }

        /** @var mixed $meta */
        $meta = $session->get(RequestMeta::class);

        if ($meta instanceof RequestMeta) {
            return $meta->clientInfo;
        }

        /** @var mixed $info */
        $info = $session->get('client_info');

        if (!is_array($info)) {
            return null;
        }

        $name = self::optionalString($info, 'name');
        $version = self::optionalString($info, 'version');

        if ($name === null || $version === null) {
            return null;
        }

        return new Implementation(
            name: $name,
            version: $version,
            description: self::optionalString($info, 'description'),
            websiteUrl: self::optionalString($info, 'websiteUrl'),
            title: self::optionalString($info, 'title'),
        );
    }

    /**
     * @param array<array-key, mixed> $info
     */
    private static function optionalString(array $info, string $key): ?string
    {
        /** @var mixed $value */
        $value = $info[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
