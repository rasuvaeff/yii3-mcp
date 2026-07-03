<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi\Exception;

use RuntimeException;

/**
 * The upstream REST call behind a bridged tool returned a non-2xx response.
 * The SDK converts it into an MCP tool error envelope.
 *
 * @api
 */
final class OperationFailedException extends RuntimeException {}
