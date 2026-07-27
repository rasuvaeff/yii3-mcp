<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentity;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentityProviderInterface;
use RuntimeException;

final class ThrowingExecutionIdentityProvider implements ExecutionIdentityProviderInterface
{
    #[\Override]
    public function current(): ExecutionIdentity
    {
        throw new RuntimeException('identity resolution failed');
    }
}
