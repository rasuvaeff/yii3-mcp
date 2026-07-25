<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;

/**
 * Builds the MCP tool input schema (JSON Schema object) for an operation:
 * one property per path/query parameter, plus a `body` property when the
 * operation has an application/json request body.
 *
 * Tool arguments are keyed by name only, so an operation declaring a path
 * and a query parameter with the same name — or a parameter named `body`
 * alongside a request body — cannot be bridged and throws at build time
 * instead of silently collapsing two inputs into one argument.
 *
 * @internal
 */
final readonly class InputSchemaBuilder
{
    public const string BODY_ARGUMENT = 'body';
    public const string DRY_RUN_ARGUMENT = 'dryRun';

    /**
     * @return array{type: 'object', properties: array<string, mixed>|\stdClass, required: list<string>}
     */
    public function build(Operation $operation, bool $dryRunnable = false): array
    {
        $properties = [];
        $required = [];

        foreach ($operation->parameters as $parameter) {
            $schema = $parameter['schema'] === [] ? ['type' => 'string'] : $parameter['schema'];

            if ($parameter['description'] !== '' && !isset($schema['description'])) {
                $schema['description'] = $parameter['description'];
            }

            if (isset($properties[$parameter['name']])) {
                throw new InvalidSpecException(sprintf(
                    'Operation "%s" declares both a path and a query parameter named "%s" — tool arguments are keyed by name only, so the operation cannot be bridged',
                    $operation->operationId,
                    $parameter['name'],
                ));
            }

            $properties[$parameter['name']] = $schema;

            if ($parameter['required']) {
                $required[] = $parameter['name'];
            }
        }

        if ($operation->requestBodySchema !== null) {
            if (isset($properties[self::BODY_ARGUMENT])) {
                throw new InvalidSpecException(sprintf(
                    'Operation "%s" declares a parameter named "%s" that collides with the request-body argument — the operation cannot be bridged',
                    $operation->operationId,
                    self::BODY_ARGUMENT,
                ));
            }

            $properties[self::BODY_ARGUMENT] = $operation->requestBodySchema;

            if ($operation->requestBodyRequired) {
                $required[] = self::BODY_ARGUMENT;
            }
        }

        if ($dryRunnable) {
            if (isset($properties[self::DRY_RUN_ARGUMENT])) {
                throw new InvalidSpecException(sprintf(
                    'Operation "%s" declares a parameter named "%s" that collides with the dry-run argument — the operation cannot be bridged with dry_run enabled',
                    $operation->operationId,
                    self::DRY_RUN_ARGUMENT,
                ));
            }

            $properties[self::DRY_RUN_ARGUMENT] = [
                'type' => 'boolean',
                'description' => 'Preview the request that would be sent, without actually executing it',
            ];
        }

        // an operation without arguments must serialize as "properties": {},
        // not "[]" — clients validate the schema as a record and reject the
        // whole tools/list otherwise. The SDK only normalizes this inside
        // Tool::fromArray(), which the bridge does not use, so do it here.
        // required stays a (possibly empty) array — null breaks the SDK's
        // opis/json-schema argument validation at call time
        return [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'required' => $required,
        ];
    }
}
