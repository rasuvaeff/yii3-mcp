<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Identity;

/**
 * Maps a presented endpoint secret to a client identity — the seam that lets
 * several clients (and several ACTIVE secrets per client, for a rotation
 * window) share one MCP endpoint without the middleware knowing where the
 * secrets live.
 *
 * Implementations MUST compare secrets in constant time ({@see hash_equals()})
 * and MUST NOT log or embed the presented secret in exceptions.
 *
 * @api
 */
interface SecretResolverInterface
{
    /**
     * The client id owning the presented secret, or null when no configured
     * secret matches (the caller rejects the request fail-closed).
     */
    public function resolve(string $presentedSecret): ?string;
}
