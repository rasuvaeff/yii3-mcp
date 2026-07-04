<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi\Exception;

use LogicException;

/**
 * An allow-listed operation uses a non-GET HTTP method while the bridge is
 * configured with `safe_methods_only` — a wiring mistake that must fail
 * fast at server build time instead of exposing a write operation.
 *
 * @api
 */
final class UnsafeOperationException extends LogicException {}
