<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use InvalidArgumentException;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Builder;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\UnsafeOperationException;
use Rasuvaeff\Yii3Mcp\ReservedToolNamesAwareInterface;
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
 * in $toolNames or $dryRunOperations throws InvalidArgumentException, and a
 * rename — from $toolNames or from $modifier — that is invalid or collides
 * with another bridged tool's name (or with an attribute tool's, which
 * McpServerFactory reserves through ReservedToolNamesAwareInterface) throws
 * InvalidSpecException.
 *
 * @api
 */
final readonly class OpenApiServerConfigurator implements ServerConfiguratorInterface, ReservedToolNamesAwareInterface
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
     * @param list<string> $dryRunOperations operationIds that get an extra `dryRun` boolean argument;
     *                                       a call with `dryRun: true` returns the planned request
     *                                       (method, url, body) instead of executing it. Orthogonal to
     *                                       $safeMethodsOnly — does not expose an operation the safety
     *                                       gate would otherwise reject.
     * @param list<string> $reservedToolNames names already taken by attribute tools; filled in by
     *                                        McpServerFactory, not by application configuration
     */
    public function __construct(
        private SpecIndex $spec,
        private HttpOperationExecutor $executor,
        private array $operations,
        private bool $safeMethodsOnly = false,
        private array $toolNames = [],
        private ?OperationModifierInterface $modifier = null,
        private array $dryRunOperations = [],
        private array $reservedToolNames = [],
    ) {}

    #[\Override]
    public function withReservedToolNames(array $names): static
    {
        return new self(
            spec: $this->spec,
            executor: $this->executor,
            operations: $this->operations,
            safeMethodsOnly: $this->safeMethodsOnly,
            toolNames: $this->toolNames,
            modifier: $this->modifier,
            dryRunOperations: $this->dryRunOperations,
            reservedToolNames: $names,
        );
    }

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

        $unknownDryRun = array_diff($this->dryRunOperations, $this->operations);

        if ($unknownDryRun !== []) {
            throw new InvalidArgumentException(sprintf(
                'dry_run contains unknown operationId%s: %s',
                count($unknownDryRun) > 1 ? 's' : '',
                implode(', ', $unknownDryRun),
            ));
        }

        $schemaBuilder = new InputSchemaBuilder();
        // seeded with the attribute tools' names: the SDK registry is
        // last-write-wins and runs explicit registrations before reflected
        // ones, so a bridged tool that picks a taken name would not override
        // the attribute tool — it would silently lose itself
        $usedNames = array_fill_keys($this->reservedToolNames, null);

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

            $dryRunnable = in_array($operationId, $this->dryRunOperations, true);

            $tool = new Tool(
                name: $name,
                title: null,
                inputSchema: $schemaBuilder->build($operation, $dryRunnable),
                description: $operation->description === '' ? null : $operation->description,
                annotations: $operation->method === 'GET' ? new ToolAnnotations(readOnlyHint: true) : null,
                meta: $operation->tags === [] ? null : ['rasuvaeff/yii3-mcp' => ['tags' => $operation->tags]],
                outputSchema: $operation->outputSchema,
            );

            if ($this->modifier instanceof OperationModifierInterface) {
                $tool = $this->modifier->modify($operation, $tool);
            }

            if ($tool->name !== $name && !ToolNameValidator::isValid($tool->name)) {
                throw new InvalidSpecException(sprintf(
                    'The operation modifier renamed operation "%s" to "%s", which cannot be used as an MCP tool name',
                    $operationId,
                    $tool->name,
                ));
            }

            if (array_key_exists($tool->name, $usedNames)) {
                throw new InvalidSpecException($usedNames[$tool->name] === null
                    ? sprintf(
                        'Operation "%s" resolves to tool name "%s", which is already registered by an attribute tool — rename it via tool_names or the operation modifier',
                        $operationId,
                        $tool->name,
                    )
                    : sprintf(
                        'Operations "%s" and "%s" both resolve to tool name "%s" — rename one of them via tool_names or the operation modifier',
                        $usedNames[$tool->name],
                        $operationId,
                        $tool->name,
                    ));
            }

            $usedNames[$tool->name] = $operationId;

            $builder->add(
                definition: $tool,
                handler: new BridgedToolHandler(operation: $operation, executor: $this->executor, dryRunnable: $dryRunnable),
            );
        }
    }
}
