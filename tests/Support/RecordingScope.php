<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Rasuvaeff\Yii3Mcp\OpenApi\InProcessScopeInterface;

/**
 * Scope double: records the enter/handle/leave interleaving so tests can
 * assert the executor balances the scope around every nested call.
 */
final class RecordingScope implements InProcessScopeInterface
{
    /** @var list<string> */
    public array $log = [];

    #[\Override]
    public function enter(): void
    {
        $this->log[] = 'enter';
    }

    #[\Override]
    public function leave(): void
    {
        $this->log[] = 'leave';
    }
}
