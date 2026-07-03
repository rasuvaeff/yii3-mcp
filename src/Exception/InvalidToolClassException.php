<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Exception;

use LogicException;

/**
 * An entry of the `tools` configuration does not exist or exposes no MCP
 * capability attributes — a wiring mistake that must fail fast.
 *
 * @api
 */
final class InvalidToolClassException extends LogicException {}
