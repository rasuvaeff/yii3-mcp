<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

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
    ) {}

    #[\Override]
    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        return $this->executor->execute($this->operation, $arguments);
    }
}
