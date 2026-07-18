<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Server\Builder;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;

final readonly class ManyCapabilitiesConfigurator implements ServerConfiguratorInterface
{
    public function __construct(
        private int $count = 21,
    ) {}

    #[\Override]
    public function configure(Builder $builder): void
    {
        for ($i = 1; $i <= $this->count; ++$i) {
            $suffix = sprintf('%02d', $i);

            $builder
                ->addTool(static fn(): string => 'ok', name: 'tool-' . $suffix)
                ->addResource(static fn(): string => 'ok', uri: 'test://resource/' . $suffix, name: 'resource-' . $suffix)
                ->addResourceTemplate(static fn(string $id): string => $id, uriTemplate: 'test://template/' . $suffix . '/{id}', name: 'template-' . $suffix)
                ->addPrompt(static fn(): string => 'ok', name: 'prompt-' . $suffix);
        }
    }
}
