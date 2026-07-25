<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use InvalidArgumentException;
use Mcp\Schema\Tool;
use Mcp\Server\Builder;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\UnsafeOperationException;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;

/**
 * Bridges allow-listed OpenAPI operations onto the MCP server: each
 * operationId becomes a tool named after it (or after its $toolNames
 * override), with the input schema derived from the operation's
 * parameters/request body, the output schema derived from its success
 * response (object-typed application/json only) and calls executed as real
 * HTTP requests against the upstream API — passing its full middleware
 * stack (validation, rate limiting, auth).
 *
 * Fail-closed: an empty allow-list exposes nothing; an operationId missing
 * from the document throws UnknownOperationException at build time; with
 * $safeMethodsOnly a non-GET operation in the allow-list throws
 * UnsafeOperationException instead of being exposed; an unknown operationId
 * in $toolNames throws InvalidArgumentException, and a rename — from
 * $toolNames or from $modifier — that is invalid or collides with another
 * tool's name throws InvalidSpecException.
 *
 * @api
 */
final readonly class OpenApiServerConfigurator implements ServerConfiguratorInterface
{
    /**
     * @param list<string> $operations allow-list of operationIds to expose
     * @param bool $safeMethodsOnly reject non-GET operations at build time —
     *                              a second line of defence for read-only bridges
     * @param array<string, string> $toolNames operationId => tool name;
     *                                         unmapped operations keep their operationId as the tool name.
     *                                         The allow-list, handler execution and delegated-header
     *                                         calls stay keyed by operationId — only the served tool
     *                                         name changes, so interceptors/visibility rules must
     *                                         reference the RENAMED name.
     * @param ?OperationModifierInterface $modifier optional per-operation customization, applied after
     *                                              the $toolNames rename; may change the tool further
     *                                              (description, annotations, name) — a further name
     *                                              change is validated and checked for collisions the
     *                                              same way as a $toolNames rename
     */
    public function __construct(
        private SpecIndex $spec,
        private HttpOperationExecutor $executor,
        private array $operations,
        private bool $safeMethodsOnly = false,
        private array $toolNames = [],
        private ?OperationModifierInterface $modifier = null,
    ) {}

    #[\Override]
    public function configure(Builder $builder): void
    {
        $unknown = array_diff(array_keys($this->toolNames), $this->operations);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'tool_names contains unknown operationId%s: %s',
                count($unknown) > 1 ? 's' : '',
                implode(', ', $unknown),
            ));
        }

        $schemaBuilder = new InputSchemaBuilder();
        $usedNames = [];

        foreach ($this->operations as $operationId) {
            $operation = $this->spec->get($operationId);

            if ($this->safeMethodsOnly && $operation->method !== 'GET') {
                throw new UnsafeOperationException(sprintf(
                    'Operation "%s" uses %s but the bridge is configured with safe_methods_only — only GET operations may be exposed',
                    $operation->operationId,
                    $operation->method,
                ));
            }

            $name = $this->toolNames[$operationId] ?? $operationId;

            if ($name !== $operationId && !ToolNameValidator::isValid($name)) {
                throw new InvalidSpecException(sprintf(
                    'tool_names renames operation "%s" to "%s", which cannot be used as an MCP tool name',
                    $operationId,
                    $name,
                ));
            }

            $tool = new Tool(
                name: $name,
                title: null,
                inputSchema: $schemaBuilder->build($operation),
                description: $operation->description === '' ? null : $operation->description,
                annotations: null,
                outputSchema: $operation->outputSchema,
            );

            if ($this->modifier !== null) {
                $tool = $this->modifier->modify($operation, $tool);
            }

            if ($tool->name !== $name && !ToolNameValidator::isValid($tool->name)) {
                throw new InvalidSpecException(sprintf(
                    'The operation modifier renamed operation "%s" to "%s", which cannot be used as an MCP tool name',
                    $operationId,
                    $tool->name,
                ));
            }

            if (isset($usedNames[$tool->name])) {
                throw new InvalidSpecException(sprintf(
                    'Operations "%s" and "%s" both resolve to tool name "%s" — rename one of them via tool_names or the operation modifier',
                    $usedNames[$tool->name],
                    $operationId,
                    $tool->name,
                ));
            }

            $usedNames[$tool->name] = $operationId;

            $builder->add(
                definition: $tool,
                handler: new BridgedToolHandler(operation: $operation, executor: $this->executor),
            );
        }
    }
}
