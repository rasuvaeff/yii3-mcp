<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use InvalidArgumentException;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidToolArgumentException;
use Rasuvaeff\Yii3Mcp\OpenApi\Operation;
use Rasuvaeff\Yii3Mcp\OpenApi\OperationRequestBuilder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(OperationRequestBuilder::class)]
final class OperationRequestBuilderTest
{
    /**
     * @param array<array-key, mixed> $arrayQueryParams
     */
    #[DataProvider('invalidStyleOverrideProvider')]
    public function malformedStyleOverridesAreRejectedWithTheirCoordinates(array $arrayQueryParams, string $message): void
    {
        $caught = null;

        try {
            new OperationRequestBuilder(baseUrl: 'https://api.test', arrayQueryParams: $arrayQueryParams);
        } catch (InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), $message);
    }

    public static function invalidStyleOverrideProvider(): iterable
    {
        yield 'operation entry is not a map' => [
            ['getItems' => 'brackets'],
            'Array query style overrides for operation "getItems" must be a parameter-name => style map',
        ];
        yield 'operation key is not a string' => [
            [7 => 'brackets'],
            'Array query style overrides for operation "int" must be a parameter-name => style map',
        ];
        yield 'unknown style' => [
            ['getItems' => ['tags' => 'pipes']],
            'Array query style for "getItems.tags" must be one of repeat, brackets, comma, "pipes" given',
        ];
        yield 'style is not a string' => [
            ['getItems' => ['tags' => 5]],
            'Array query style for "getItems.tags" must be one of repeat, brackets, comma, int given',
        ];
        yield 'parameter key is not a string' => [
            ['getItems' => [3 => 'pipes']],
            'Array query style for "getItems.int" must be one of repeat, brackets, comma, "pipes" given',
        ];
        yield 'numeric operation key with an unknown style' => [
            [7 => ['tags' => 'pipes']],
            'Array query style for "int.tags" must be one of repeat, brackets, comma, "pipes" given',
        ];
    }

    #[DataProvider('arrayTypeProvider')]
    public function onlyABareArrayOrANullableArrayUnionIsSerializedAsAnArray(mixed $type, bool $isArray): void
    {
        $builder = new OperationRequestBuilder(baseUrl: 'https://api.test');
        $operation = $this->operation([$this->query('tags', ['type' => $type])]);

        if ($isArray) {
            Assert::same($builder->plan($operation, ['tags' => ['a', 'b']], 'getItems')->url, 'https://api.test/items?tags=a&tags=b');

            return;
        }

        $caught = null;

        try {
            $builder->plan($operation, ['tags' => ['a', 'b']], 'getItems');
        } catch (InvalidToolArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), 'Argument "tags" of tool "getItems" must be a scalar');
    }

    public static function arrayTypeProvider(): iterable
    {
        yield 'bare array' => ['array', true];
        yield 'array or null' => [['array', 'null'], true];
        yield 'null or array' => [['null', 'array'], true];
        yield 'string' => ['string', false];
        yield 'single element union' => [['array'], false];
        yield 'nullable string' => [['string', 'null'], false];
        yield 'array or string' => [['array', 'string'], false];
        yield 'union of three' => [['array', 'null', 'string'], false];
        yield 'not a type at all' => [null, false];
    }

    public function anArrayArgumentForAPathParameterIsRefusedAsNonScalar(): void
    {
        $builder = new OperationRequestBuilder(baseUrl: 'https://api.test');
        $operation = $this->operation([$this->parameter('id', 'path', ['type' => 'array'])], path: '/items/{id}');

        $caught = null;

        try {
            $builder->plan($operation, ['id' => ['1', '2']], 'getItem');
        } catch (InvalidToolArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), 'Argument "id" of tool "getItem" must be a scalar');
    }

    public function aScalarForAnArrayQueryParameterIsSentAsOneValue(): void
    {
        $builder = new OperationRequestBuilder(baseUrl: 'https://api.test');
        $operation = $this->operation([$this->query('tags', ['type' => 'array'])]);

        Assert::same($builder->plan($operation, ['tags' => 'solo'], 'getItems')->url, 'https://api.test/items?tags=solo');
    }

    public function anEmptyArrayParameterDoesNotHideTheOnesAfterIt(): void
    {
        $builder = new OperationRequestBuilder(baseUrl: 'https://api.test');
        $operation = $this->operation([
            $this->query('none', ['type' => 'array']),
            $this->query('tags', ['type' => 'array']),
        ]);

        Assert::same(
            $builder->plan($operation, ['none' => [], 'tags' => ['a', 'b']], 'getItems')->url,
            'https://api.test/items?tags=a&tags=b',
        );
    }

    #[DataProvider('styleProvider')]
    public function anEmptyArrayConstrainsNothingInAnyStyle(string $style): void
    {
        $builder = new OperationRequestBuilder(baseUrl: 'https://api.test', arrayQueryStyle: $style);
        $operation = $this->operation([$this->query('tags', ['type' => 'array'])]);

        Assert::same($builder->plan($operation, ['tags' => []], 'getItems')->url, 'https://api.test/items');
    }

    public static function styleProvider(): iterable
    {
        yield 'repeat' => ['repeat'];
        yield 'brackets' => ['brackets'];
        yield 'comma' => ['comma'];
    }

    public function itemCountsAtTheDeclaredBoundsAreAccepted(): void
    {
        $builder = new OperationRequestBuilder(baseUrl: 'https://api.test');
        $operation = $this->operation([$this->query('tags', ['type' => 'array', 'minItems' => 2, 'maxItems' => 2])]);

        Assert::same($builder->plan($operation, ['tags' => ['a', 'b']], 'getItems')->url, 'https://api.test/items?tags=a&tags=b');
    }

    #[DataProvider('itemBoundProvider')]
    public function itemCountsOutsideTheDeclaredBoundsAreRefusedWithAnExactMessage(array $schema, array $values, string $message): void
    {
        $builder = new OperationRequestBuilder(baseUrl: 'https://api.test');
        $operation = $this->operation([$this->query('tags', ['type' => 'array', ...$schema])]);

        $caught = null;

        try {
            $builder->plan($operation, ['tags' => $values], 'getItems');
        } catch (InvalidToolArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), $message);
    }

    public static function itemBoundProvider(): iterable
    {
        yield 'one over a maximum of one' => [['maxItems' => 1], ['a', 'b'], 'Argument "tags" of tool "getItems" must contain at most 1 item'];
        yield 'one over a maximum of two' => [['maxItems' => 2], ['a', 'b', 'c'], 'Argument "tags" of tool "getItems" must contain at most 2 items'];
        yield 'one under a minimum of one' => [['minItems' => 1], [], 'Argument "tags" of tool "getItems" must contain at least 1 item'];
        yield 'one under a minimum of two' => [['minItems' => 2], ['a'], 'Argument "tags" of tool "getItems" must contain at least 2 items'];
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array{name: non-empty-string, in: 'path'|'query'|'header'|'cookie', required: bool, schema: array<array-key, mixed>, description: string, style: ?string, explode: ?bool, allowReserved: bool}
     */
    private function query(string $name, array $schema): array
    {
        return $this->parameter($name, 'query', $schema);
    }

    /**
     * @param non-empty-string $name
     * @param 'path'|'query'|'header'|'cookie' $in
     * @param array<string, mixed> $schema
     *
     * @return array{name: non-empty-string, in: 'path'|'query'|'header'|'cookie', required: bool, schema: array<array-key, mixed>, description: string, style: ?string, explode: ?bool, allowReserved: bool}
     */
    private function parameter(string $name, string $in, array $schema): array
    {
        return [
            'name' => $name,
            'in' => $in,
            'required' => false,
            'schema' => $schema,
            'description' => '',
            'style' => null,
            'explode' => null,
            'allowReserved' => false,
        ];
    }

    /**
     * @param list<array{name: non-empty-string, in: 'path'|'query'|'header'|'cookie', required: bool, schema: array<array-key, mixed>, description: string, style: ?string, explode: ?bool, allowReserved: bool}> $parameters
     * @param non-empty-string $path
     */
    private function operation(array $parameters, string $path = '/items'): Operation
    {
        return new Operation(
            operationId: 'getItems',
            method: 'GET',
            path: $path,
            description: '',
            parameters: $parameters,
            requestBodySchema: null,
            requestBodyRequired: false,
        );
    }
}
