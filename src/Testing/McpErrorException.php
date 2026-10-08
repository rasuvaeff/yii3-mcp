<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Testing;

use RuntimeException;

/**
 * A JSON-RPC error answered to a {@see McpTester} request, with the whole
 * envelope: tests that compare two refusals (a hidden capability against a
 * missing one) must compare the code and data too, not only the text.
 *
 * @api
 */
final class McpErrorException extends RuntimeException
{
    public function __construct(
        public readonly int $errorCode,
        public readonly string $errorMessage,
        public readonly mixed $errorData = null,
    ) {
        parent::__construct(sprintf('MCP error: %s', $errorMessage));
    }
}
