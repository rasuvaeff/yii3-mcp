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
 * FPM-safe: a PHP-FPM worker handles one request at a time, and the
 * arm/disarm bracket guarantees no identity leaks into the next request.
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
