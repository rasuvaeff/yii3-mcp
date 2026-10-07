<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

/**
 * Executes one bridged OpenAPI operation from the raw MCP argument bag.
 *
 * Two implementations ship: {@see HttpOperationExecutor} (a real PSR-18 call
 * against the upstream API) and {@see Psr15OperationExecutor} (the
 * application's own request handler, in-process). Both share the request
 * assembly and response decoding through {@see OperationRequestBuilder} and
 * {@see OperationResponseDecoder}, so a bridged call behaves identically
 * apart from the transport.
 *
 * @internal
 */
interface OperationExecutorInterface
{
    /**
     * @param array<string, mixed> $arguments tool arguments keyed by parameter name
     * @param bool $dryRunnable whether the operation was configured for dry-run previews
     * @param ?string $toolName the name this operation is SERVED under (after
     *                          `tool_names` and the operation modifier); null
     *                          falls back to the operationId, which is correct
     *                          only when the operation was never renamed
     *
     * @throws Exception\InvalidToolArgumentException an argument failed a caller-argument guard
     * @throws Exception\OperationFailedException the upstream answered a non-2xx/3xx status
     *                                            or exceeded the response cap
     */
    public function execute(Operation $operation, array $arguments, bool $dryRunnable = false, ?string $toolName = null): mixed;
}
