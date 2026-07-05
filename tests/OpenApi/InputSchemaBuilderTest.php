<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidSpecException;
use Rasuvaeff\Yii3Mcp\OpenApi\InputSchemaBuilder;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(InputSchemaBuilder::class)]
final class InputSchemaBuilderTest
{
    private SpecIndex $spec;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->spec = new SpecIndex(OpenApiFixture::spec());
    }

    public function buildsSchemaFromQueryParameters(): void
    {
        $schema = (new InputSchemaBuilder())->build($this->spec->get('getBlogTags'));

        Assert::same($schema['type'], 'object');
        Assert::same($schema['properties']['locale']['type'], 'string');
        Assert::same($schema['properties']['locale']['description'], 'Locale code');
        Assert::same($schema['required'], []);
    }

    public function pathParametersAreAlwaysRequired(): void
    {
        $schema = (new InputSchemaBuilder())->build($this->spec->get('getBlogTagBySlug'));

        Assert::same($schema['required'], ['slug']);
    }

    public function parameterWithoutSchemaDefaultsToString(): void
    {
        $operation = (new SpecIndex([
            'paths' => ['/x' => ['get' => [
                'operationId' => 'op',
                'parameters' => [['name' => 'bare', 'in' => 'query', 'description' => 'no schema at all']],
            ]]],
        ]))->get('op');

        $schema = (new InputSchemaBuilder())->build($operation);

        Assert::same($schema['properties']['bare'], [
            'type' => 'string',
            'description' => 'no schema at all',
        ]);
    }

    public function parameterDescriptionDoesNotOverrideSchemaDescription(): void
    {
        $operation = (new SpecIndex([
            'paths' => ['/x' => ['get' => [
                'operationId' => 'op',
                'parameters' => [[
                    'name' => 'p',
                    'in' => 'query',
                    'description' => 'parameter level',
                    'schema' => ['type' => 'integer', 'description' => 'schema level'],
                ]],
            ]]],
        ]))->get('op');

        $schema = (new InputSchemaBuilder())->build($operation);

        Assert::same($schema['properties']['p'], [
            'type' => 'integer',
            'description' => 'schema level',
        ]);
    }

    public function requestBodyBecomesBodyProperty(): void
    {
        $schema = (new InputSchemaBuilder())->build($this->spec->get('createSubscriber'));

        Assert::same($schema['properties']['body']['required'], ['email']);
        Assert::same($schema['required'], ['body']);
    }

    public function samePathAndQueryParameterNameCannotBeBridged(): void
    {
        $operation = (new SpecIndex([
            'paths' => ['/items/{id}' => ['get' => [
                'operationId' => 'op',
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'schema' => ['type' => 'integer']],
                    ['name' => 'id', 'in' => 'query'],
                ],
            ]]],
        ]))->get('op');

        $caught = null;

        try {
            (new InputSchemaBuilder())->build($operation);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('named "id"');
    }

    public function parameterNamedBodyCollidesWithRequestBodyArgument(): void
    {
        $operation = (new SpecIndex([
            'paths' => ['/x' => ['post' => [
                'operationId' => 'op',
                'parameters' => [['name' => 'body', 'in' => 'query']],
                'requestBody' => [
                    'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                ],
            ]]],
        ]))->get('op');

        $caught = null;

        try {
            (new InputSchemaBuilder())->build($operation);
        } catch (InvalidSpecException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('request-body argument');
    }
}
