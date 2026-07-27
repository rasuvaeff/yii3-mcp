<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;

/**
 * Asserts an operation's parameters fit what {@see HttpOperationExecutor}
 * can actually serialize: path/query only, scalar schemas, default styles,
 * no reserved-character passthrough. This is the executor's CONTRACT made
 * explicit at build time — an unsupported parameter fails the server build
 * instead of producing a silently wrong upstream request at call time.
 *
 * @internal
 */
final readonly class OperationContractValidator
{
    public function validate(Operation $operation): void
    {
        foreach ($operation->parameters as $parameter) {
            if ($parameter['in'] === 'header' || $parameter['in'] === 'cookie') {
                throw new InvalidSpecException(sprintf(
                    'Operation "%s" uses unsupported %s parameter "%s"; configure fixed headers on HttpOperationExecutor or expose a custom tool',
                    $operation->operationId,
                    $parameter['in'],
                    $parameter['name'],
                ));
            }

            $schema = $parameter['schema'];
            /** @var mixed $rawType */
            $rawType = $schema === [] ? 'string' : ($schema['type'] ?? null);
            $type = $this->resolveScalarType($rawType);

            if ($type === null) {
                throw new InvalidSpecException(sprintf(
                    'Operation "%s" parameter "%s" must use a scalar schema; type %s is not supported by the HTTP executor',
                    $operation->operationId,
                    $parameter['name'],
                    json_encode($rawType, JSON_THROW_ON_ERROR),
                ));
            }

            $expectedStyle = $parameter['in'] === 'path' ? 'simple' : 'form';

            if ($parameter['style'] !== null && $parameter['style'] !== $expectedStyle) {
                throw new InvalidSpecException(sprintf(
                    'Operation "%s" parameter "%s" uses unsupported serialization style "%s"; only "%s" is supported for %s parameters',
                    $operation->operationId,
                    $parameter['name'],
                    $parameter['style'],
                    $expectedStyle,
                    $parameter['in'],
                ));
            }

            $expectedExplode = $parameter['in'] === 'query';

            if ($parameter['explode'] !== null && $parameter['explode'] !== $expectedExplode) {
                throw new InvalidSpecException(sprintf(
                    'Operation "%s" parameter "%s" uses unsupported explode=%s',
                    $operation->operationId,
                    $parameter['name'],
                    $parameter['explode'] ? 'true' : 'false',
                ));
            }

            if ($parameter['allowReserved']) {
                throw new InvalidSpecException(sprintf(
                    'Operation "%s" parameter "%s" uses unsupported allowReserved=true',
                    $operation->operationId,
                    $parameter['name'],
                ));
            }
        }
    }

    /**
     * Accepts a plain scalar type string (OpenAPI 3.0, e.g. `"string"`) or a
     * two-element nullable union (OpenAPI 3.1, e.g. `["string", "null"]` or
     * `["null", "integer"]`). The null branch itself needs no schema-side
     * handling: HttpOperationExecutor skips null-valued arguments entirely.
     */
    private function resolveScalarType(mixed $type): ?string
    {
        if (is_string($type)) {
            return in_array($type, ['string', 'integer', 'number', 'boolean'], strict: true) ? $type : null;
        }

        if (!is_array($type) || count($type) !== 2 || !in_array('null', $type, strict: true)) {
            return null;
        }

        /** @var mixed $candidate */
        foreach ($type as $candidate) {
            if (is_string($candidate) && in_array($candidate, ['string', 'integer', 'number', 'boolean'], strict: true)) {
                return $candidate;
            }
        }

        return null;
    }
}
