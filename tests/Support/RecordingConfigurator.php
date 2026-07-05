<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Server\Builder;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;

final class RecordingConfigurator implements ServerConfiguratorInterface
{
    public bool $configured = false;

    #[\Override]
    public function configure(Builder $builder): void
    {
        $this->configured = true;
    }
}
