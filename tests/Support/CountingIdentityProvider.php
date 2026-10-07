<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentity;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentityProviderInterface;

/**
 * Identity-provider double that counts resolutions — the request builder
 * must resolve the identity exactly once per call even when both delegated
 * headers and request attributes derive from it.
 */
final class CountingIdentityProvider implements ExecutionIdentityProviderInterface
{
    public int $calls = 0;

    public function __construct(
        public ExecutionIdentity $identity,
    ) {}

    #[\Override]
    public function current(): ExecutionIdentity
    {
        ++$this->calls;

        return $this->identity;
    }
}
