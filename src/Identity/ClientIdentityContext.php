<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Identity;

/**
 * Process-local carrier of the client id for the CURRENT request: the SDK's
 * reference handler receives the JSON-RPC request (not the PSR-7 one), so
 * the id resolved by {@see \Rasuvaeff\Yii3Mcp\SharedSecretMiddleware} cannot
 * travel as a request attribute all the way down —
 * {@see \Rasuvaeff\Yii3Mcp\McpAction} arms this holder before running the
 * transport and disarms it in a finally block.
 *
 * This holder is deliberately NOT the primary identity source: capability
 * calls read the client id from the session's immutable owner (stamped at
 * `initialize` by McpAction), which travels with the request and stays
 * correct when requests interleave. The holder covers only the fallback
 * paths — sessionless calls and sessions with no recorded owner — and a
 * disagreement between holder and owner fails closed
 * ({@see \Rasuvaeff\Yii3Mcp\Exception\SessionOwnershipException}).
 *
 * Concurrency contract: one mutable slot per PHP process. Safe under
 * PHP-FPM/CLI (one request at a time, arm/disarm bracketed). In a
 * concurrent or reentrant runtime (Swoole, AMPHP, RoadRunner with
 * interleaved Fibers) the FALLBACK paths above may observe another
 * request's id — do not rely on unstamped-session attribution there; the
 * owner-stamped main path is unaffected.
 *
 * @internal
 */
final class ClientIdentityContext
{
    private static ?string $clientId = null;

    private function __construct()
    {
        // Static holder; not instantiable.
    }

    public static function arm(?string $clientId): void
    {
        self::$clientId = $clientId;
    }

    public static function current(): ?string
    {
        return self::$clientId;
    }

    public static function disarm(): void
    {
        self::$clientId = null;
    }
}
