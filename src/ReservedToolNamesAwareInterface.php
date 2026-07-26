<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

/**
 * A ServerConfiguratorInterface that wants to know which tool names the
 * attribute-based tools already occupy, so it can fail fast instead of
 * registering a colliding one.
 *
 * The SDK's registry is last-write-wins and its loaders run explicit
 * registrations (Builder::add(), used by configurators) BEFORE reflected
 * ones (Builder::addTool(), used for #[McpTool] methods) — so a configurator
 * that picks an already-taken name does not overwrite the attribute tool, it
 * silently loses its own. McpServerFactory hands the reserved names to every
 * configurator implementing this interface before calling configure().
 *
 * @api
 */
interface ReservedToolNamesAwareInterface
{
    /**
     * @param list<string> $names tool names already registered from #[McpTool] attributes
     */
    public function withReservedToolNames(array $names): static;
}
