<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi\Exception;

use InvalidArgumentException;

/**
 * A bridged tool was called with arguments the executor refuses: a
 * non-scalar URL parameter, a path argument that could escape the
 * allow-listed route, a missing required path parameter, or a malformed
 * `dryRun` flag.
 *
 * Distinct from a bare `InvalidArgumentException` on purpose.
 * {@see \Rasuvaeff\Yii3Mcp\OpenApi\BridgedToolHandler} rethrows THIS type as
 * the SDK's ToolCallException so its message reaches the caller, and the
 * message describes only the caller's own arguments. The PSR-17/PSR-18 stack
 * underneath throws plain `InvalidArgumentException`s of its own — an
 * unparseable request URI, a delegated header name that is not RFC 7230
 * compatible — and those describe the deployment, not the call: they must
 * stay internal, so they are deliberately NOT caught.
 *
 * @api
 */
final class InvalidToolArgumentException extends InvalidArgumentException {}
