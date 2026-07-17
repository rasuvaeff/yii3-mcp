<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\UnknownOperationException;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(SpecIndex::class)]
final class SpecIndexTest
{
    public function indexesOperationById(): void
    {
        $operation = (new SpecIndex(OpenApiFixture::spec()))->get('getBlogTags');

        Assert::same($operation->operationId, 'getBlogTags');
        Assert::same($operation->method, 'GET');
        Assert::same($operation->path, '/rest/blog-tags');
        Assert::same($operation->description, 'List blog tags');
    }

    public function mergesPathLevelParameters(): void
    {
        $operation = (new SpecIndex(OpenApiFixture::spec()))->get('getBlogTagBySlug');

        Assert::same($operation->parameters[0]['name'], 'slug');
        Assert::same($operation->parameters[0]['in'], 'path');
        Assert::true($operation->parameters[0]['required']);
    }

    public function resolvesRequestBodyRef(): void
    {
        $operation = (new SpecIndex(OpenApiFixture::spec()))->get('createSubscriber');

        Assert::same($operation->requestBodySchema['required'] ?? null, ['email']);
        Assert::true($operation->requestBodyRequired);
    }

    public function resolvesRequiredFlagFromReferencedRequestBody(): void
    {
        $operation = (new SpecIndex([
            'paths' => ['/x' => ['post' => [
                'operationId' => 'op',
                'requestBody' => ['$ref' => '#/components/requestBodies/Payload'],
            ]]],
            'components' => ['requestBodies' => ['Payload' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
            ]]],
        ]))->get('op');

        Assert::true($operation->requestBodyRequired);
        Assert::same($operation->requestBodySchema, ['type' => 'object']);
    }

    public function throwsOnDuplicateOperationId(): void
    {
        $caught = null;

        try {
            new SpecIndex([
                'paths' => [
                    '/first' => ['get' => ['operationId' => 'duplicate']],
                    '/second' => ['post' => ['operationId' => 'duplicate']],
                ],
            ]);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('GET /first');
        Assert::string($caught->getMessage())->contains('POST /second');
    }

    public function throwsOnUnknownOperation(): void
    {
        Expect::exception(UnknownOperationException::class);

        (new SpecIndex(OpenApiFixture::spec()))->get('nonExistent');
    }

    public function operationWithoutIdIsNotIndexed(): void
    {
        Expect::exception(UnknownOperationException::class);

        // "/rest/no-id" get has no operationId — it must not be reachable
        (new SpecIndex(OpenApiFixture::spec()))->get('');
    }

    public function throwsOnSpecWithoutPaths(): void
    {
        Expect::exception(InvalidSpecException::class);

        new SpecIndex(['openapi' => '3.0.0']);
    }

    public function fromJsonRejectsMalformedDocument(): void
    {
        Expect::exception(InvalidSpecException::class);

        SpecIndex::fromJson('{broken');
    }

    public function fromFileRejectsMissingFile(): void
    {
        Expect::exception(InvalidSpecException::class);

        SpecIndex::fromFile('/nonexistent/spec.json');
    }

    public function fromJsonParsesDocument(): void
    {
        $index = SpecIndex::fromJson(json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));

        Assert::same($index->get('getBlogTags')->operationId, 'getBlogTags');
    }

    public function throwsOnUnresolvableRef(): void
    {
        $spec = OpenApiFixture::spec();
        $spec['paths']['/rest/subscriber']['post']['requestBody']['content']['application/json']['schema']['$ref'] = '#/components/schemas/Missing';

        $caught = null;

        try {
            new SpecIndex($spec);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('Unresolvable');
    }

    public function fromJsonReportsInvalidJsonSpecifically(): void
    {
        $caught = null;

        try {
            SpecIndex::fromJson('{broken');
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('not valid JSON');
    }

    public function numericPathKeyIsSkipped(): void
    {
        $index = new SpecIndex([
            'paths' => [
                '0' => ['get' => ['operationId' => 'zeroOp']],
                '/ok' => ['get' => ['operationId' => 'okOp']],
            ],
        ]);

        Expect::exception(UnknownOperationException::class);

        $index->get('zeroOp');
    }

    public function nonArrayPathItemIsSkipped(): void
    {
        $index = new SpecIndex([
            'paths' => [
                '/garbage' => 'not-a-path-item',
                '/ok' => ['get' => ['operationId' => 'okOp']],
            ],
        ]);

        Assert::same($index->get('okOp')->operationId, 'okOp');
    }

    public function emptyOperationIdIsNotIndexedEvenWithValidPath(): void
    {
        $index = new SpecIndex([
            'paths' => [
                '/x' => ['get' => ['operationId' => '']],
                '/ok' => ['get' => ['operationId' => 'okOp']],
            ],
        ]);

        Expect::exception(UnknownOperationException::class);

        $index->get('');
    }

    public function descriptionWinsOverSummary(): void
    {
        $index = new SpecIndex([
            'paths' => [
                '/x' => ['get' => [
                    'operationId' => 'op',
                    'summary' => 'short summary',
                    'description' => 'long description',
                ]],
            ],
        ]);

        Assert::same($index->get('op')->description, 'long description');
    }

    public function requestBodyWithoutRequiredFlagIsOptional(): void
    {
        $index = new SpecIndex([
            'paths' => [
                '/x' => ['post' => [
                    'operationId' => 'op',
                    'requestBody' => [
                        'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                    ],
                ]],
            ],
        ]);

        Assert::false($index->get('op')->requestBodyRequired);
    }

    public function parametersAreNormalizedExactly(): void
    {
        $index = new SpecIndex([
            'paths' => [
                '/items/{id}' => [
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'schema' => ['type' => 'integer']],
                        ['name' => 'inherited', 'in' => 'query', 'description' => 'from path level'],
                    ],
                    'get' => [
                        'operationId' => 'richOp',
                        'parameters' => [
                            // нестроковое имя — отфильтрован
                            ['name' => 42, 'in' => 'query'],
                            // одноимённый с path-параметром, но другой in — оба должны остаться
                            ['name' => 'id', 'in' => 'query', 'required' => true],
                            // переопределяет path-level запись с тем же in+name
                            ['name' => 'inherited', 'in' => 'query', 'description' => 'overridden'],
                            // не-scalar required → строгий bool false
                            ['name' => 'flagless', 'in' => 'query', 'required' => 0],
                        ],
                    ],
                ],
            ],
        ]);

        Assert::same($index->get('richOp')->parameters, [
            [
                'name' => 'id',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'integer'],
                'description' => '',
                'style' => null,
                'explode' => null,
                'allowReserved' => false,
            ],
            [
                'name' => 'inherited',
                'in' => 'query',
                'required' => false,
                'schema' => [],
                'description' => 'overridden',
                'style' => null,
                'explode' => null,
                'allowReserved' => false,
            ],
            [
                'name' => 'id',
                'in' => 'query',
                'required' => true,
                'schema' => [],
                'description' => '',
                'style' => null,
                'explode' => null,
                'allowReserved' => false,
            ],
            [
                'name' => 'flagless',
                'in' => 'query',
                'required' => false,
                'schema' => [],
                'description' => '',
                'style' => null,
                'explode' => null,
                'allowReserved' => false,
            ],
        ]);
    }

    public function unsupportedHeaderParameterFailsWhenOperationIsSelected(): void
    {
        $index = new SpecIndex([
            'paths' => [
                '/unsupported' => ['get' => [
                    'operationId' => 'unsupported',
                    'parameters' => [['name' => 'X-Trace', 'in' => 'header', 'required' => true]],
                ]],
                '/supported' => ['get' => ['operationId' => 'supported']],
            ],
        ]);

        Assert::same($index->get('supported')->operationId, 'supported');

        $caught = null;

        try {
            $index->get('unsupported');
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('unsupported header parameter "X-Trace"');
    }

    public function unsupportedCookieParameterThrows(): void
    {
        Expect::exception(InvalidSpecException::class);

        $this->operationWithParameter(['name' => 'session', 'in' => 'cookie']);
    }

    public function arrayParameterSchemaThrows(): void
    {
        $caught = null;

        try {
            $this->operationWithParameter(['name' => 'ids', 'in' => 'query', 'schema' => ['type' => 'array']]);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('must use a scalar schema');
        Assert::string($caught->getMessage())->contains('"array"');
    }

    public function unsupportedSerializationOptionsThrow(): void
    {
        foreach ([
            ['name' => 'id', 'in' => 'path', 'style' => 'matrix'],
            ['name' => 'q', 'in' => 'query', 'explode' => false],
            ['name' => 'q', 'in' => 'query', 'allowReserved' => true],
        ] as $parameter) {
            $caught = null;

            try {
                $this->operationWithParameter($parameter);
            } catch (InvalidSpecException $caught) {
            }

            Assert::notNull($caught);
        }
    }

    public function refSiblingsSurviveResolution(): void
    {
        $index = new SpecIndex([
            'paths' => [
                '/x' => ['post' => [
                    'operationId' => 'op',
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => [
                            '$ref' => '#/components/schemas/Base',
                            'description' => 'local override',
                            'example' => ['a' => 1],
                        ]]],
                    ],
                ]],
            ],
            'components' => ['schemas' => ['Base' => [
                'type' => 'object',
                'properties' => ['nested' => ['$ref' => '#/components/schemas/Leaf']],
            ], 'Leaf' => ['type' => 'string']]],
        ]);

        Assert::same($index->get('op')->requestBodySchema, [
            'type' => 'object',
            'properties' => ['nested' => ['type' => 'string']],
            'description' => 'local override',
            'example' => ['a' => 1],
        ]);
    }

    public function jsonPointerEscapesAreDecoded(): void
    {
        $index = new SpecIndex([
            'paths' => [
                '/x' => ['get' => [
                    'operationId' => 'op',
                    'parameters' => [
                        ['name' => 'p', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/a~1b~0c']],
                    ],
                ]],
            ],
            'components' => ['schemas' => ['a/b~c' => ['type' => 'string']]],
        ]);

        Assert::same($index->get('op')->parameters[0]['schema'], ['type' => 'string']);
    }

    public function refChainAtTheLimitIsAccepted(): void
    {
        Assert::same(
            $this->indexWithRefChainOfLength(32)->get('op')->parameters[0]['schema'],
            ['type' => 'string'],
        );
    }

    public function refChainBeyondTheLimitThrows(): void
    {
        Expect::exception(InvalidSpecException::class);

        $this->indexWithRefChainOfLength(33);
    }

    public function deepSchemaNestingWithoutRefsIsNotLimited(): void
    {
        Assert::same($this->indexWithSchemaOfDepth(40)->get('op')->operationId, 'op');
    }

    public function circularRefIsReportedAsTooDeepChain(): void
    {
        $caught = null;

        try {
            new SpecIndex([
                'paths' => [
                    '/x' => ['get' => [
                        'operationId' => 'op',
                        'parameters' => [['name' => 'p', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/A']]],
                    ]],
                ],
                'components' => ['schemas' => [
                    'A' => ['$ref' => '#/components/schemas/B'],
                    'B' => ['$ref' => '#/components/schemas/A'],
                ]],
            ]);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('$ref chain is too deep');
    }

    public function selfReferencingSchemaThrows(): void
    {
        Expect::exception(InvalidSpecException::class);

        new SpecIndex([
            'paths' => [
                '/x' => ['post' => [
                    'operationId' => 'op',
                    'requestBody' => [
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Node']]],
                    ],
                ]],
            ],
            'components' => ['schemas' => ['Node' => [
                'type' => 'object',
                'properties' => ['child' => ['$ref' => '#/components/schemas/Node']],
            ]]],
        ]);
    }

    public function refToScalarLeafThrows(): void
    {
        $caught = null;

        try {
            new SpecIndex([
                'paths' => [
                    '/x' => ['get' => [
                        'operationId' => 'op',
                        'parameters' => [['name' => 'p', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/Scalar']]],
                    ]],
                ],
                'components' => ['schemas' => ['Scalar' => 'just-a-string']],
            ]);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('must point to an object');
    }

    public function externalParameterRefThrowsBecauseScalarTypeCannotBeVerified(): void
    {
        $index = new SpecIndex([
            'paths' => [
                '/x' => ['get' => [
                    'operationId' => 'op',
                    'parameters' => [['name' => 'p', 'in' => 'query', 'schema' => ['$ref' => 'https://example.com/schemas.json#/Thing']]],
                ]],
            ],
        ]);

        Expect::exception(InvalidSpecException::class);

        $index->get('op');
    }

    private function indexWithRefChainOfLength(int $length): SpecIndex
    {
        $schemas = ['S' . $length => ['type' => 'string']];
        for ($i = $length - 1; $i >= 1; --$i) {
            $schemas['S' . $i] = ['$ref' => '#/components/schemas/S' . ($i + 1)];
        }

        return new SpecIndex([
            'paths' => [
                '/x' => ['get' => [
                    'operationId' => 'op',
                    'parameters' => [['name' => 'p', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/S1']]],
                ]],
            ],
            'components' => ['schemas' => $schemas],
        ]);
    }

    /**
     * @param array<string, mixed> $parameter
     */
    private function operationWithParameter(array $parameter): \Rasuvaeff\Yii3Mcp\OpenApi\Operation
    {
        return (new SpecIndex([
            'paths' => ['/x' => ['get' => [
                'operationId' => 'op',
                'parameters' => [$parameter],
            ]]],
        ]))->get('op');
    }

    private function indexWithSchemaOfDepth(int $depth): SpecIndex
    {
        $schema = ['type' => 'string'];
        for ($i = 0; $i < $depth; ++$i) {
            $schema = ['type' => 'string', 'allOf' => [$schema]];
        }

        return new SpecIndex([
            'paths' => [
                '/x' => ['get' => [
                    'operationId' => 'op',
                    'parameters' => [['name' => 'p', 'in' => 'query', 'schema' => $schema]],
                ]],
            ],
        ]);
    }
}
