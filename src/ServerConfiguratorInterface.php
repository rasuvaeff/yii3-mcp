<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Mcp\Server\Builder;

/**
 * Extension point: contributes additional capabilities to the SDK server
 * builder before it is built (OpenAPI bridge, hand-registered prompts, …).
 *
 * @api
 */
interface ServerConfiguratorInterface
{
    public function configure(Builder $builder): void;
}
