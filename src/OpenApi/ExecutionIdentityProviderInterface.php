<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

/**
 * Resolves the current application identity at operation execution time.
 *
 * @api
 */
interface ExecutionIdentityProviderInterface
{
    public function current(): ExecutionIdentity;
}
