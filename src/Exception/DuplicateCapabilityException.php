<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Exception;

use LogicException;

/**
 * Two capability registrations resolved to the same identity (tool/prompt
 * name, resource URI, resource template). The SDK registry is last-write-wins
 * and would silently serve only one of them — while visibility rules, cache
 * partitions, RBAC and audit keep referring to the name, possibly describing
 * the handler that vanished. Registration is trusted developer input, so a
 * collision is a wiring mistake that must fail the server build.
 *
 * @api
 */
final class DuplicateCapabilityException extends LogicException {}
