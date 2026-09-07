<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use InvalidArgumentException;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\OperationFailedException;

/**
 * Executes one bridged OpenAPI operation with the raw MCP argument bag.
 *
 * @internal
 */
final readonly class BridgedToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private Operation $operation,
        private HttpOperationExecutor $executor,
        private bool $dryRunnable = false,
        private ?string $toolName = null,
    ) {}

    #[\Override]
    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        try {
            return $this->executor->execute($this->operation, $arguments, $this->dryRunnable, $this->toolName);
        } catch (OperationFailedException|InvalidArgumentException $e) {
            // The SDK's CallToolHandler turns ONLY ToolCallException into a
            // tool-error envelope carrying the message; every other Throwable
            // becomes Error::forInternalError('Error while executing tool')
            // and the message is dropped before it reaches the caller. Both
            // exception classes here describe the caller's own call — the
            // upstream status (already redacted when opaque_errors is set)
            // and the arguments it passed — so both belong in the envelope.
            throw new ToolCallException($e->getMessage(), previous: $e);
        }
    }
}
