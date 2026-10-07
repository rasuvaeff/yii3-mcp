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

            if ($this->isArrayType($rawType)) {
                $this->validateArrayQueryParameter($operation, $parameter, $schema);

                continue;
            }

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
     * Array-typed QUERY parameters are the norm for list/search filters
     * (`?platforms=a&platforms=b`); arrays anywhere else (a path segment, in
     * particular) still fail closed — the same guards as for scalars, plus
     * the items schema: scalar items only, no nested arrays or objects.
     */
    private function validateArrayQueryParameter(Operation $operation, array $parameter, array $schema): void
    {
        if ($parameter['in'] !== 'query') {
            throw new InvalidSpecException(sprintf(
                'Operation "%s" parameter "%s" uses an array schema outside a query parameter; array parameters are supported for query only',
                $operation->operationId,
                $parameter['name'],
            ));
        }

        /** @var mixed $items */
        $items = $schema['items'] ?? null;

        if (!is_array($items) || $this->resolveScalarType($items['type'] ?? null) === null) {
            throw new InvalidSpecException(sprintf(
                'Operation "%s" parameter "%s" must be an array of scalar items; objects and nested arrays are not supported',
                $operation->operationId,
                $parameter['name'],
            ));
        }

        if ($parameter['style'] !== null && $parameter['style'] !== 'form') {
            throw new InvalidSpecException(sprintf(
                'Operation "%s" parameter "%s" uses unsupported serialization style "%s"; only "form" is supported for %s parameters',
                $operation->operationId,
                $parameter['name'],
                $parameter['style'],
                $parameter['in'],
            ));
        }

        // explode=false (comma-separated) is a legal OpenAPI form for arrays
        // and maps to the 'comma' serialization; everything else repeats the
        // key. No expectedExplode check here, unlike the scalar branch.

        if ($parameter['allowReserved']) {
            throw new InvalidSpecException(sprintf(
                'Operation "%s" parameter "%s" uses unsupported allowReserved=true',
                $operation->operationId,
                $parameter['name'],
            ));
        }
    }

    /**
     * Mirrors the union handling of {@see resolveScalarType()}: a bare
     * "array" (OpenAPI 3.0) or a two-element nullable union containing it
     * (OpenAPI 3.1).
     */
    private function isArrayType(mixed $type): bool
    {
        if (is_string($type)) {
            return $type === 'array';
        }

        return is_array($type)
            && count($type) === 2
            && in_array('array', $type, strict: true)
            && in_array('null', $type, strict: true);
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
