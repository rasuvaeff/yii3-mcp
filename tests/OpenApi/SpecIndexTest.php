<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\UnknownOperationException;
use Rasuvaeff\Yii3Mcp\OpenApi\JsonPointerResolver;
use Rasuvaeff\Yii3Mcp\OpenApi\Operation;
use Rasuvaeff\Yii3Mcp\OpenApi\OperationContractValidator;
use Rasuvaeff\Yii3Mcp\OpenApi\OutputSchemaProjector;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Yii3Mcp\OpenApi\ToolNameValidator;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(SpecIndex::class)]
#[Covers(InvalidSpecException::class)]
#[Covers(UnknownOperationException::class)]
#[Covers(JsonPointerResolver::class)]
#[Covers(Operation::class)]
#[Covers(OperationContractValidator::class)]
#[Covers(OutputSchemaProjector::class)]
#[Covers(ToolNameValidator::class)]
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

    public function pathWithoutLeadingSlashIsNotIndexed(): void
    {
        // HttpOperationExecutor concatenates baseUrl . path with no
        // separator; a path lacking the leading slash would splice into the
        // host ("https://api.test" + "evil.com/x" = "https://api.testevil.com/x").
        // The OpenAPI spec itself requires every Path Item key to start with
        // "/" — this is also just rejecting a non-conformant document.
        $index = new SpecIndex([
            'paths' => [
                'evil.com/x' => ['get' => ['operationId' => 'op']],
                '/ok' => ['get' => ['operationId' => 'okOp']],
            ],
        ]);

        Expect::exception(UnknownOperationException::class);

        $index->get('op');
    }

    public function tagsAreExtractedFromTheOperation(): void
    {
        $index = new SpecIndex([
            'paths' => ['/x' => ['get' => ['operationId' => 'op', 'tags' => ['admin', 'reporting']]]],
        ]);

        Assert::same($index->get('op')->tags, ['admin', 'reporting']);
    }

    public function operationWithoutTagsHasAnEmptyTagsList(): void
    {
        $index = new SpecIndex(['paths' => ['/x' => ['get' => ['operationId' => 'op']]]]);

        Assert::same($index->get('op')->tags, []);
    }

    public function nonStringTagEntriesAreDropped(): void
    {
        $index = new SpecIndex([
            'paths' => ['/x' => ['get' => ['operationId' => 'op', 'tags' => ['admin', 42, '', null]]]],
        ]);

        Assert::same($index->get('op')->tags, ['admin']);
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

    public function throwsOnToolNameWithASpace(): void
    {
        $index = new SpecIndex([
            'paths' => ['/x' => ['get' => ['operationId' => 'get user']]],
        ]);

        $caught = null;

        try {
            $index->get('get user');
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('get user');
    }

    public function toolNameWithTrailingNewlineIsRejected(): void
    {
        $index = new SpecIndex([
            'paths' => ['/x' => ['get' => ['operationId' => "validName\n"]]],
        ]);

        Expect::exception(InvalidSpecException::class);

        $index->get("validName\n");
    }

    public function toolNameAtMaxLengthIsAccepted(): void
    {
        $name = str_repeat('a', 64);
        $index = new SpecIndex(['paths' => ['/x' => ['get' => ['operationId' => $name]]]]);

        Assert::same($index->get($name)->operationId, $name);
    }

    public function toolNameBeyondMaxLengthIsRejected(): void
    {
        $name = str_repeat('a', 65);
        $index = new SpecIndex(['paths' => ['/x' => ['get' => ['operationId' => $name]]]]);

        Expect::exception(InvalidSpecException::class);

        $index->get($name);
    }

    public function toolNameWithAllowedCharsIsAccepted(): void
    {
        $name = 'get.user_by-id/v2';
        $index = new SpecIndex(['paths' => ['/x' => ['get' => ['operationId' => $name]]]]);

        Assert::same($index->get($name)->operationId, $name);
    }

    public function toolNameWithUnicodeIsRejected(): void
    {
        $name = 'gëtUser';
        $index = new SpecIndex(['paths' => ['/x' => ['get' => ['operationId' => $name]]]]);

        Expect::exception(InvalidSpecException::class);

        $index->get($name);
    }

    public function nullableUnionScalarTypeIsAccepted(): void
    {
        $operation = $this->operationWithParameter([
            'name' => 'q',
            'in' => 'query',
            'schema' => ['type' => ['string', 'null']],
        ]);

        Assert::same($operation->parameters[0]['schema'], ['type' => ['string', 'null']]);
    }

    public function nullFirstUnionScalarTypeIsAccepted(): void
    {
        $operation = $this->operationWithParameter([
            'name' => 'q',
            'in' => 'query',
            'schema' => ['type' => ['null', 'integer']],
        ]);

        Assert::same($operation->parameters[0]['schema']['type'], ['null', 'integer']);
    }

    public function unionTypeWithTwoScalarMembersThrows(): void
    {
        Expect::exception(InvalidSpecException::class);

        $this->operationWithParameter(['name' => 'q', 'in' => 'query', 'schema' => ['type' => ['string', 'integer']]]);
    }

    public function unionTypeWithUnsupportedScalarThrows(): void
    {
        $caught = null;

        try {
            $this->operationWithParameter(['name' => 'q', 'in' => 'query', 'schema' => ['type' => ['array', 'null']]]);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('["array","null"]');
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

    public function styleMismatchMessageNamesTheExpectedStylePerParameterLocation(): void
    {
        $caught = null;

        try {
            $this->operationWithParameter(['name' => 'id', 'in' => 'path', 'style' => 'matrix']);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('only "simple" is supported for path parameters');

        $caught = null;

        try {
            $this->operationWithParameter(['name' => 'q', 'in' => 'query', 'style' => 'spaceDelimited']);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('only "form" is supported for query parameters');
    }

    public function explodeMismatchMessageNamesTheActualExplodeValue(): void
    {
        $caught = null;

        try {
            // query parameters expect explode=true; false is the mismatch
            $this->operationWithParameter(['name' => 'q', 'in' => 'query', 'explode' => false]);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('unsupported explode=false');

        $caught = null;

        try {
            // path parameters expect explode=false; true is the mismatch
            $this->operationWithParameter(['name' => 'id', 'in' => 'path', 'explode' => true]);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('unsupported explode=true');
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

    public function refFanOutBeyondTheNodeBudgetIsRejected(): void
    {
        // a compact document whose shared schemas EXPAND combinatorially:
        // four levels of 20-way fan-out inline to ~20^4 nodes — a hostile or
        // degenerate remote spec must hit the resolution budget, not OOM
        $components = ['leaf' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]]];
        $previous = 'leaf';

        foreach (['l1', 'l2', 'l3', 'l4'] as $level) {
            $properties = [];

            for ($i = 0; $i < 20; ++$i) {
                $properties['p' . $i] = ['$ref' => '#/components/schemas/' . $previous];
            }

            $components[$level] = ['type' => 'object', 'properties' => $properties];
            $previous = $level;
        }

        $caught = null;

        try {
            new SpecIndex([
                'paths' => [
                    '/fan' => ['post' => [
                        'operationId' => 'fanOut',
                        'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/l4']]]],
                    ]],
                ],
                'components' => ['schemas' => $components],
            ]);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('reference-resolution budget');
    }

    /**
     * Exercises JsonPointerResolver::resolve() directly (not through
     * SpecIndex) for exact control over the node count: a wide, shallow
     * fan-out — one root plus N leaf-array children, each child a single
     * resolve() call — avoids the deep RECURSION a chain of the same length
     * would need (resolve() recurses once per nesting level, so a 100k-deep
     * chain would risk exhausting the C stack; a 2-level-deep fan-out never
     * does).
     */
    public function nodeBudgetAtTheLimitIsAccepted(): void
    {
        $resolver = new JsonPointerResolver([]);

        // 1 (root) + 99_999 (children) = 100_000, exactly MAX_RESOLVED_NODES
        $node = $this->wideNode(99_999);

        Assert::same($resolver->resolve($node), $node);
    }

    public function nodeBudgetBeyondTheLimitThrows(): void
    {
        $resolver = new JsonPointerResolver([]);

        // 1 (root) + 100_000 (children) = 100_001, one over MAX_RESOLVED_NODES
        $node = $this->wideNode(100_000);

        Expect::exception(InvalidSpecException::class);

        $resolver->resolve($node);
    }

    /**
     * @return array<string, array{leaf: int}>
     */
    private function wideNode(int $children): array
    {
        $node = [];

        for ($i = 0; $i < $children; ++$i) {
            $node['c' . $i] = ['leaf' => 1];
        }

        return $node;
    }

    public function oversizedDocumentIsRejectedBeforeDecoding(): void
    {
        $caught = null;

        try {
            SpecIndex::fromJson('{"pad":"' . str_repeat('a', SpecIndex::MAX_DOCUMENT_BYTES) . '"}');
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('byte limit');
    }

    public function documentExactlyAtTheLimitParses(): void
    {
        $spec = OpenApiFixture::spec();
        $spec['x-pad'] = '';
        $missing = SpecIndex::MAX_DOCUMENT_BYTES - strlen(json_encode($spec, JSON_THROW_ON_ERROR));
        $spec['x-pad'] = str_repeat('a', $missing);
        $json = json_encode($spec, JSON_THROW_ON_ERROR);
        Assert::same(strlen($json), SpecIndex::MAX_DOCUMENT_BYTES);

        Assert::same(SpecIndex::fromJson($json)->get('getBlogTags')->operationId, 'getBlogTags');
    }

    public function fromFileLoadsAValidDocument(): void
    {
        $path = sys_get_temp_dir() . '/yii3-mcp-spec-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));

        try {
            Assert::same(SpecIndex::fromFile($path)->get('getBlogTags')->operationId, 'getBlogTags');
        } finally {
            @unlink($path);
        }
    }

    public function fromFileReportsAMissingFileAsUnreadable(): void
    {
        $caught = null;

        try {
            SpecIndex::fromFile(sys_get_temp_dir() . '/yii3-mcp-spec-void-' . bin2hex(random_bytes(8)) . '.json');
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('is not readable');
    }

    public function fromFileRejectsAnOversizedDocumentByItsPath(): void
    {
        $path = sys_get_temp_dir() . '/yii3-mcp-spec-big-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, str_repeat('a', SpecIndex::MAX_DOCUMENT_BYTES + 1));

        $caught = null;

        try {
            SpecIndex::fromFile($path);
        } catch (InvalidSpecException $caught) {
        } finally {
            @unlink($path);
        }

        Assert::notNull($caught);
        // rejected on filesize, BEFORE buffering: the message names the path
        // and the size — the generic fromJson message has neither
        Assert::string($caught->getMessage())
            ->contains($path)
            ->contains(sprintf('of %d bytes', SpecIndex::MAX_DOCUMENT_BYTES + 1));
    }

    public function operationWithoutTagsHasNoTags(): void
    {
        $index = new SpecIndex([
            'paths' => ['/x' => ['get' => ['operationId' => 'op']]],
        ]);

        Assert::same($index->get('op')->tags, []);
    }

    public function parameterWithAnEmptyNameIsSkipped(): void
    {
        $index = new SpecIndex([
            'paths' => ['/x' => ['get' => [
                'operationId' => 'op',
                'parameters' => [['name' => '', 'in' => 'query', 'schema' => ['type' => 'string']]],
            ]]],
        ]);

        Assert::same($index->get('op')->parameters, []);
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

    public function outputSchemaComesFromTheObjectTypedSuccessResponse(): void
    {
        $operation = (new SpecIndex(OpenApiFixture::spec()))->get('getBlogTagBySlug');

        Assert::same($operation->outputSchema, [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string'],
                'title' => ['type' => 'string'],
            ],
            'required' => ['slug'],
        ]);
    }

    public function arrayResponseIsNotAdvertisedAsOutputSchema(): void
    {
        $operation = (new SpecIndex(OpenApiFixture::spec()))->get('getBlogTags');

        Assert::null($operation->outputSchema);
    }

    public function lowestConcreteTwoxxResponseWinsAndWildcardIsIgnored(): void
    {
        $operation = (new SpecIndex(OpenApiFixture::spec()))->get('createSubscriber');

        Assert::same($operation->outputSchema, [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ]);
    }

    public function nullableUnionObjectResponseIsAdvertisedAsOutputSchema(): void
    {
        $schema = $this->operationWithResponses([
            '200' => ['content' => ['application/json' => ['schema' => [
                'type' => ['object', 'null'],
                'properties' => ['slug' => ['type' => 'string']],
            ]]]],
        ])->outputSchema;

        Assert::same($schema, ['type' => 'object', 'properties' => ['slug' => ['type' => 'string']]]);
    }

    public function nullFirstUnionObjectResponseIsAdvertisedAsOutputSchema(): void
    {
        $schema = $this->operationWithResponses([
            '200' => ['content' => ['application/json' => ['schema' => ['type' => ['null', 'object']]]]],
        ])->outputSchema;

        Assert::same($schema, ['type' => 'object']);
    }

    public function operationWithoutResponsesHasNoOutputSchema(): void
    {
        Assert::null($this->operationWithResponses(null)->outputSchema);
    }

    public function nonTwoxxOnlyResponsesYieldNoOutputSchema(): void
    {
        Assert::null($this->operationWithResponses([
            '404' => ['description' => 'Not found', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
        ])->outputSchema);
    }

    public function successResponseWithoutJsonContentYieldsNoOutputSchema(): void
    {
        Assert::null($this->operationWithResponses([
            '200' => ['description' => 'CSV export', 'content' => ['text/csv' => ['schema' => ['type' => 'string']]]],
        ])->outputSchema);
    }

    public function schemalessJsonSuccessResponseYieldsNoOutputSchema(): void
    {
        Assert::null($this->operationWithResponses([
            '200' => ['description' => 'Anything', 'content' => ['application/json' => []]],
        ])->outputSchema);
    }

    public function boundaryStatusCodesOutsideTwoxxAreIgnored(): void
    {
        $objectContent = ['content' => ['application/json' => ['schema' => ['type' => 'object']]]];

        Assert::null($this->operationWithResponses([
            '199' => ['description' => 'Informational'] + $objectContent,
            '300' => ['description' => 'Redirect'] + $objectContent,
        ])->outputSchema);
        Assert::same($this->operationWithResponses([
            '299' => ['description' => 'Edge of the range'] + $objectContent,
        ])->outputSchema, ['type' => 'object']);
    }

    public function outputSchemaIsCanonicalizedToTheMcpShape(): void
    {
        $schema = $this->operationWithResponses([
            '200' => ['content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'description' => 'A tag',
                'properties' => ['slug' => ['type' => 'string'], 0 => ['type' => 'ignored']],
                'required' => ['slug', 42],
                'additionalProperties' => false,
                'xml' => ['name' => 'tag'],
                'example' => ['slug' => 'php'],
            ]]]],
        ])->outputSchema;

        Assert::same($schema, [
            'type' => 'object',
            'properties' => ['slug' => ['type' => 'string']],
            'required' => ['slug'],
            'additionalProperties' => false,
            'description' => 'A tag',
        ]);
    }

    public function objectTypedAdditionalPropertiesSchemaIsKept(): void
    {
        $schema = $this->operationWithResponses([
            '200' => ['content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'string', 0 => 'dropped-int-key'],
            ]]]],
        ])->outputSchema;

        Assert::same($schema, [
            'type' => 'object',
            'additionalProperties' => ['type' => 'string'],
        ]);
    }

    public function emptyObjectTypedAdditionalPropertiesAreOmittedFromOutputSchema(): void
    {
        // kept as an empty array it would serialize to "additionalProperties":
        // [] and be rejected by clients (JSON Schema requires a boolean or a
        // schema object there); an empty schema object matches anything,
        // which is exactly what omitting the (optional) key also means
        $schema = $this->operationWithResponses([
            '200' => ['content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'additionalProperties' => [],
            ]]]],
        ])->outputSchema;

        Assert::same($schema, ['type' => 'object']);
    }

    public function emptyPropertiesAreOmittedFromOutputSchema(): void
    {
        // kept as an empty array it would serialize to "properties": [] and be
        // rejected as "not a record"; the key is optional, so it is dropped
        $schema = $this->operationWithResponses([
            '200' => ['content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ]]]],
        ])->outputSchema;

        Assert::same($schema, ['type' => 'object', 'required' => []]);
    }

    public function emptyDescriptionIsOmittedFromOutputSchema(): void
    {
        $schema = $this->operationWithResponses([
            '200' => ['content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'description' => '',
            ]]]],
        ])->outputSchema;

        Assert::same($schema, ['type' => 'object']);
    }

    /**
     * @param array<string, mixed>|null $responses
     */
    private function operationWithResponses(?array $responses): \Rasuvaeff\Yii3Mcp\OpenApi\Operation
    {
        $raw = ['operationId' => 'op'];

        if ($responses !== null) {
            $raw['responses'] = $responses;
        }

        return (new SpecIndex(['paths' => ['/x' => ['get' => $raw]]]))->get('op');
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
