<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Visibility;

use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Server\Session\SessionInterface;

/**
 * Per-session resource visibility: filters resources/list and
 * resources/templates/list AND fail-closed guards resources/read — a hidden
 * resource (or any URI matching a hidden template) cannot be read even by
 * its exact URI (it is reported as not found, indistinguishable from a
 * missing one). One implementation serves both paths so list and read can
 * never disagree.
 *
 * @api
 */
interface ResourceVisibilityInterface
{
    public function isVisible(ResourceDefinition $resource, ?SessionInterface $session): bool;

    public function isTemplateVisible(ResourceTemplate $template, ?SessionInterface $session): bool;
}
