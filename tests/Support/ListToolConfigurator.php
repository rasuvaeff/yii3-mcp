<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Server\Builder;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;

/**
 * A tool whose result is a JSON list — structured content only from
 * 2026-07-28 on.
 */
final readonly class ListToolConfigurator implements ServerConfiguratorInterface
{
    #[\Override]
    public function configure(Builder $builder): void
    {
        $builder->addTool(static fn(): array => [1, 2], name: 'ids');
    }
}
