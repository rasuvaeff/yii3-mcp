<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi\Exception;

use LogicException;

/**
 * An allow-listed operationId is not present in the OpenAPI document —
 * a wiring mistake that must fail fast at server build time.
 *
 * @api
 */
final class UnknownOperationException extends LogicException {}
