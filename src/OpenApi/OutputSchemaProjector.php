<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

/**
 * Projects an operation's OpenAPI success response onto the MCP tool
 * `outputSchema` shape: the lowest concrete 2xx response with an
 * `application/json` OBJECT schema. MCP requires `outputSchema` to be of
 * type "object", so array/scalar responses (and `2XX` wildcards) are not
 * advertised — `structuredContent` still flows for their JSON object
 * payloads, just without an upfront contract.
 *
 * The result is canonicalized to the MCP tool output-schema keywords
 * (`type`, `properties`, `required`, `additionalProperties`, `description`);
 * other top-level keywords are dropped.
 *
 * @internal
 */
final readonly class OutputSchemaProjector
{
    public function __construct(
        private JsonPointerResolver $resolver,
    ) {}

    /**
     * @param array<array-key, mixed> $responses
     *
     * @return array{type: 'object', properties?: array<string, mixed>, required?: list<string>, additionalProperties?: array<string, mixed>|bool, description?: string}|null
     */
    public function project(array $responses): ?array
    {
        $codes = [];

        foreach (array_keys($responses) as $code) {
            if (is_int($code) && $code >= 200 && $code <= 299) {
                $codes[] = $code;
            }
        }

        if ($codes === []) {
            return null;
        }

        $response = $this->resolver->resolve($this->arrayOrEmpty($responses[min($codes)] ?? null));
        $content = $this->arrayOrEmpty($response['content'] ?? null);
        $json = $this->arrayOrEmpty($content['application/json'] ?? null);
        $schema = $this->arrayOrEmpty($json['schema'] ?? null);

        if ($schema === [] || !$this->isObjectType($schema['type'] ?? null)) {
            return null;
        }

        $output = ['type' => 'object'];
        /** @var mixed $properties */
        $properties = $schema['properties'] ?? null;

        if (is_array($properties)) {
            $named = [];
            /** @var mixed $property */
            foreach ($properties as $name => $property) {
                if (is_string($name)) {
                    /** @var mixed */
                    $named[$name] = $property;
                }
            }

            // an empty map would serialize as [] and be rejected as "not a
            // record" by clients validating the schema; the key is optional,
            // so an object without declared properties simply omits it
            if ($named !== []) {
                $output['properties'] = $named;
            }
        }

        /** @var mixed $required */
        $required = $schema['required'] ?? null;

        if (is_array($required)) {
            $names = [];
            /** @var mixed $name */
            foreach ($required as $name) {
                if (is_string($name)) {
                    $names[] = $name;
                }
            }

            $output['required'] = $names;
        }

        /** @var mixed $additionalProperties */
        $additionalProperties = $schema['additionalProperties'] ?? null;

        if (is_bool($additionalProperties)) {
            $output['additionalProperties'] = $additionalProperties;
        } elseif (is_array($additionalProperties)) {
            $nested = [];
            /** @var mixed $value */
            foreach ($additionalProperties as $key => $value) {
                if (is_string($key)) {
                    /** @var mixed */
                    $nested[$key] = $value;
                }
            }

            // same reasoning as `properties` above: an empty map would
            // serialize as [] and be rejected as "not a record"/"not a
            // boolean" by clients validating the schema; an empty schema
            // object ({}) matches anything, which is exactly what omitting
            // the (optional) key also means, so we simply omit it
            if ($nested !== []) {
                $output['additionalProperties'] = $nested;
            }
        }

        /** @var mixed $description */
        $description = $schema['description'] ?? null;

        if (is_string($description) && $description !== '') {
            $output['description'] = $description;
        }

        return $output;
    }

    /**
     * Accepts the plain `"object"` type string (OpenAPI 3.0) or the 3.1
     * nullable union `["object", "null"]` (or `["null", "object"]`); the
     * output schema is always canonicalized to `type: "object"` regardless.
     */
    private function isObjectType(mixed $type): bool
    {
        if ($type === 'object') {
            return true;
        }

        return is_array($type) && count($type) === 2
            && in_array('object', $type, strict: true)
            && in_array('null', $type, strict: true);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
