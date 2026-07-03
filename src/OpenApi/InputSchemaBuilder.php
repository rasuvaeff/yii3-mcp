<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

/**
 * Builds the MCP tool input schema (JSON Schema object) for an operation:
 * one property per path/query parameter, plus a `body` property when the
 * operation has an application/json request body.
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

            $properties[$parameter['name']] = $schema;

            if ($parameter['required']) {
                $required[] = $parameter['name'];
            }
        }

        if ($operation->requestBodySchema !== null) {
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
