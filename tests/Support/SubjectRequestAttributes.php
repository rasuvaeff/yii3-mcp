<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentity;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionRequestAttributesInterface;

/**
 * Attributes-mapper double: exposes the delegated subject id the way an
 * application's CurrentUser middleware would read it.
 */
final class SubjectRequestAttributes implements ExecutionRequestAttributesInterface
{
    #[\Override]
    public function attributes(ExecutionIdentity $identity): array
    {
        return ['current_user_id' => $identity->subjectId];
    }
}
