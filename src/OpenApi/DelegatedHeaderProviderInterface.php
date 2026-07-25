<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

/**
 * Exchanges an application identity for upstream headers on every bridged
 * operation call. Implementations must not forward the inbound MCP
 * Authorization/shared-secret header verbatim.
 *
 * @api
 */
interface DelegatedHeaderProviderInterface
{
    /**
     * @return array<string, string>
     */
    public function headers(
        string $operationId,
        string $method,
        string $path,
        ExecutionIdentity $identity,
    ): array;
}
