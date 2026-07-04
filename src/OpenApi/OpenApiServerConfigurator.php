<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use Mcp\Schema\Tool;
use Mcp\Server\Builder;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\UnsafeOperationException;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;

/**
 * Bridges allow-listed OpenAPI operations onto the MCP server: each
 * operationId becomes a tool named after it, with the input schema derived
 * from the operation's parameters/request body and calls executed as real
 * HTTP requests against the upstream API — passing its full middleware
 * stack (validation, rate limiting, auth).
 *
 * Fail-closed: an empty allow-list exposes nothing; an operationId missing
 * from the document throws UnknownOperationException at build time; with
 * $safeMethodsOnly a non-GET operation in the allow-list throws
 * UnsafeOperationException instead of being exposed.
 *
 * @api
 */
final readonly class OpenApiServerConfigurator implements ServerConfiguratorInterface
{
    /**
     * @param list<string> $operations allow-list of operationIds to expose
     * @param bool $safeMethodsOnly reject non-GET operations at build time —
     *                              a second line of defence for read-only bridges
     */
    public function __construct(
        private SpecIndex $spec,
        private HttpOperationExecutor $executor,
        private array $operations,
        private bool $safeMethodsOnly = false,
    ) {}

    #[\Override]
    public function configure(Builder $builder): void
    {
        $schemaBuilder = new InputSchemaBuilder();

        foreach ($this->operations as $operationId) {
            $operation = $this->spec->get($operationId);

            if ($this->safeMethodsOnly && $operation->method !== 'GET') {
                throw new UnsafeOperationException(sprintf(
                    'Operation "%s" uses %s but the bridge is configured with safe_methods_only — only GET operations may be exposed',
                    $operation->operationId,
                    $operation->method,
                ));
            }

            $builder->add(
                definition: new Tool(
                    name: $operation->operationId,
                    title: null,
                    inputSchema: $schemaBuilder->build($operation),
                    description: $operation->description === '' ? null : $operation->description,
                    annotations: null,
                ),
                handler: new BridgedToolHandler(operation: $operation, executor: $this->executor),
            );
        }
    }
}
