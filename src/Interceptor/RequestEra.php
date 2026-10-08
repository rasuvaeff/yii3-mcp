<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Stateless\InputContext;
use Mcp\Server\Stateless\RequestMeta;

/**
 * Which protocol era the request in flight belongs to, read off the session
 * the SDK hands a capability call. The modern era (2026-07-28) has no
 * session of its own: the SDK builds a throwaway one per request and puts the
 * request's `_meta` on it as {@see RequestMeta} — the same lookup the SDK's
 * RequestContext does. The one place that knows that key: if the SDK moves
 * it, this breaks alone.
 *
 * @internal
 */
final readonly class RequestEra
{
    public static function meta(?SessionInterface $session): ?RequestMeta
    {
        /** @var mixed $meta */
        $meta = $session?->get(RequestMeta::class);

        return $meta instanceof RequestMeta ? $meta : null;
    }

    public static function isModern(?SessionInterface $session): bool
    {
        return self::meta($session) instanceof RequestMeta;
    }

    /**
     * Whether this call is a later round of a multi round-trip call — the
     * client re-sending it with answers (`inputResponses`/`requestState`),
     * which the SDK lifts onto the session as {@see InputContext}.
     */
    public static function isRetry(?SessionInterface $session): bool
    {
        return $session?->get(InputContext::class) instanceof InputContext;
    }
}
