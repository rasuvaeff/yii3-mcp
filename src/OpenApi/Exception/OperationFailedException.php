<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi\Exception;

use RuntimeException;

/**
 * The upstream REST call behind a bridged tool returned a non-2xx response,
 * or its body could not be buffered within the configured cap.
 *
 * {@see \Rasuvaeff\Yii3Mcp\OpenApi\BridgedToolHandler} rethrows it as the
 * SDK's ToolCallException so the message reaches the caller as a tool-error
 * envelope — the SDK drops the message of any other exception type.
 *
 * @api
 */
final class OperationFailedException extends RuntimeException {}
