<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentity;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentityProviderInterface;

final class MutableExecutionIdentityProvider implements ExecutionIdentityProviderInterface
{
    public function __construct(
        public ExecutionIdentity $identity,
    ) {}

    #[\Override]
    public function current(): ExecutionIdentity
    {
        return $this->identity;
    }
}
