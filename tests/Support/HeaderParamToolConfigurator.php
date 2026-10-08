<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Schema\Tool;
use Mcp\Server\Builder;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;

/**
 * A tool with an `x-mcp-header` argument (SEP-2243): on the stateless era a
 * call must mirror it as `Mcp-Param-Region`, which a client can only do after
 * reading the tool's schema from tools/list.
 */
final readonly class HeaderParamToolConfigurator implements ServerConfiguratorInterface
{
    #[\Override]
    public function configure(Builder $builder): void
    {
        $builder->add(
            new Tool(
                name: 'report.by-region',
                title: null,
                inputSchema: [
                    'type' => 'object',
                    'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']],
                    'required' => ['region'],
                ],
                description: null,
                annotations: null,
            ),
            new class implements ToolHandlerInterface {
                #[\Override]
                public function execute(array $arguments, ClientGateway $gateway): mixed
                {
                    return 'report for ' . ($arguments['region'] ?? '');
                }
            },
        );
    }
}
