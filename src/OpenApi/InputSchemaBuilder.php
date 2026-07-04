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

    /**
     * @return array{type: 'object', properties: array<string, mixed>, required: list<string>}
     */
    public function build(Operation $operation): array
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

        // the SDK's Tool normalizes empty properties to {} on serialization;
        // required stays a (possibly empty) array — null breaks the SDK's
        // opis/json-schema argument validation at call time
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }
}
