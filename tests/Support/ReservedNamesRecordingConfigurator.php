<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Server\Builder;
use Rasuvaeff\Yii3Mcp\ReservedToolNamesAwareInterface;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;

final class ReservedNamesRecordingConfigurator implements ServerConfiguratorInterface, ReservedToolNamesAwareInterface
{
    /** @var ?list<string> */
    public ?array $reserved = null;

    public bool $configured = false;

    #[\Override]
    public function withReservedToolNames(array $names): static
    {
        // deliberately mutates and returns itself: the test needs to read
        // back what the factory handed over, and a clone would hide it
        $this->reserved = $names;

        return $this;
    }

    #[\Override]
    public function configure(Builder $builder): void
    {
        $this->configured = true;
    }
}
