<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use Mcp\Schema\Tool;

/**
 * Per-operation customization hook for the OpenAPI bridge: called once per
 * bridged operation, after OpenApiServerConfigurator has built the Tool
 * (including any `tool_names` rename), so implementations see the tool as
 * it would otherwise be served and may return a modified copy — a different
 * description, annotations, or a further name change (fail-closed: the
 * returned name is validated and checked for collisions exactly like a
 * `tool_names` rename).
 *
 * For server-wide setup (registering unrelated capabilities) use
 * ServerConfiguratorInterface instead; this hook is per-operation only.
 *
 * @api
 */
interface OperationModifierInterface
{
    public function modify(Operation $operation, Tool $tool): Tool;
}
