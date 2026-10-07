<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

/**
 * Maps the resolved {@see ExecutionIdentity} to request attributes on the
 * nested in-process request ({@see Psr15OperationExecutor}), so the
 * application's stack can authenticate the user behind the MCP client the
 * usual way — attributes, not just delegated headers.
 *
 * Implementations are resolved through the DI container and called once per
 * bridged operation call, with the SAME identity resolution the delegated
 * headers were minted from.
 *
 * @api
 */
interface ExecutionRequestAttributesInterface
{
    /**
     * @return array<string, mixed> attribute name => value, set on the nested request
     *                              via withAttribute(); empty = no attributes
     */
    public function attributes(ExecutionIdentity $identity): array;
}
