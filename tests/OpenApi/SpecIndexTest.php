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
                            // header-параметры мостом не поддерживаются — отфильтрован,
                            // и фильтрация не должна прерывать обработку остальных
                            ['name' => 'X-Trace', 'in' => 'header', 'schema' => ['type' => 'string']],
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
            ],
            [
                'name' => 'inherited',
                'in' => 'query',
                'required' => false,
                'schema' => [],
                'description' => 'overridden',
            ],
            [
                'name' => 'id',
                'in' => 'query',
                'required' => true,
                'schema' => [],
                'description' => '',
            ],
            [
                'name' => 'flagless',
                'in' => 'query',
                'required' => false,
                'schema' => [],
                'description' => '',
            ],
        ]);
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

    public function refNestingAtTheLimitIsAccepted(): void
    {
        Assert::same(
            $this->indexWithSchemaOfDepth(31)->get('op')->operationId,
            'op',
        );
    }

    public function refNestingBeyondTheLimitThrows(): void
    {
        Expect::exception(InvalidSpecException::class);

        $this->indexWithSchemaOfDepth(40);
    }

    private function indexWithSchemaOfDepth(int $depth): SpecIndex
    {
        $schema = ['type' => 'string'];
        for ($i = 0; $i < $depth; ++$i) {
            $schema = ['items' => $schema];
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
