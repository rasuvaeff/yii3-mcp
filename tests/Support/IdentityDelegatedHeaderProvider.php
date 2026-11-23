<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Rasuvaeff\Mcp\Identity\ExecutionIdentity;
use Rasuvaeff\Yii3Mcp\OpenApi\DelegatedHeaderProviderInterface;

final readonly class IdentityDelegatedHeaderProvider implements DelegatedHeaderProviderInterface
{
    #[\Override]
    public function headers(
        string $operationId,
        string $method,
        string $path,
        ExecutionIdentity $identity,
    ): array {
        if ($identity->subjectId === null || $identity->tenantId === null) {
            throw new \RuntimeException('Delegated identity is incomplete');
        }

        return [
            'Authorization' => sprintf('Bearer %s:%s', $identity->tenantId, $identity->subjectId),
            'X-Upstream-Operation' => $operationId,
        ];
    }
}
