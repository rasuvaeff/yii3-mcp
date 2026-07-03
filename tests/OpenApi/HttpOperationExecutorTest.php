<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\OperationFailedException;
use Rasuvaeff\Yii3Mcp\OpenApi\HttpOperationExecutor;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeHttpClient;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(HttpOperationExecutor::class)]
final class HttpOperationExecutorTest
{
    public function buildsUrlWithQueryParameters(): void
    {
        $client = new FakeHttpClient();

        $result = $this->executor($client)->execute($this->operation('getBlogTags'), ['locale' => 'en']);

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/rest/blog-tags?locale=en');
        Assert::same($client->lastRequest?->getMethod(), 'GET');
        Assert::same($result, ['ok' => true]);
    }

    public function substitutesAndEncodesPathParameters(): void
    {
        $client = new FakeHttpClient();

        $this->executor($client)->execute($this->operation('getBlogTagBySlug'), ['slug' => 'a b/c']);

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/rest/blog-tag/a%20b%2Fc');
    }

    public function missingPathParameterThrows(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->executor(new FakeHttpClient())->execute($this->operation('getBlogTagBySlug'), []);
    }

    public function sendsJsonRequestBody(): void
    {
        $client = new FakeHttpClient();

        $this->executor($client)->execute(
            $this->operation('createSubscriber'),
            ['body' => ['email' => 'user@example.com']],
        );

        Assert::same($client->lastRequest?->getMethod(), 'POST');
        Assert::same($client->lastRequest?->getHeaderLine('Content-Type'), 'application/json');
        Assert::same((string) $client->lastRequest?->getBody(), '{"email":"user@example.com"}');
    }

    public function appliesDefaultHeaders(): void
    {
        $client = new FakeHttpClient();

        $this->executor($client, headers: ['Authorization' => 'Bearer token-1'])
            ->execute($this->operation('getBlogTags'), []);

        Assert::same($client->lastRequest?->getHeaderLine('Authorization'), 'Bearer token-1');
        Assert::same($client->lastRequest?->getHeaderLine('Accept'), 'application/json');
    }

    public function nonSuccessResponseThrows(): void
    {
        $executor = $this->executor(new FakeHttpClient(statusCode: 422, body: '{"error":"validation"}'));

        Expect::exception(OperationFailedException::class);

        $executor->execute($this->operation('getBlogTags'), []);
    }

    public function nonJsonResponseIsReturnedAsString(): void
    {
        $executor = $this->executor(new FakeHttpClient(body: 'plain text'));

        Assert::same($executor->execute($this->operation('getBlogTags'), []), 'plain text');
    }

    public function nonScalarArgumentForUrlParameterThrows(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->executor(new FakeHttpClient())->execute($this->operation('getBlogTags'), ['locale' => ['en']]);
    }

    public function emptyBaseUrlThrows(): void
    {
        $factory = new Psr17Factory();

        Expect::exception(InvalidArgumentException::class);

        new HttpOperationExecutor(
            httpClient: new FakeHttpClient(),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: '  ',
        );
    }

    public function bodyArgumentIsIgnoredForBodylessOperations(): void
    {
        $client = new FakeHttpClient();

        $this->executor($client)->execute($this->operation('getBlogTags'), ['body' => ['x' => 1]]);

        Assert::same((string) $client->lastRequest?->getBody(), '');
        Assert::same($client->lastRequest?->getHeaderLine('Content-Type'), '');
    }

    public function status299IsSuccess(): void
    {
        $executor = $this->executor(new FakeHttpClient(statusCode: 299, body: '{"ok":1}'));

        Assert::same($executor->execute($this->operation('getBlogTags'), []), ['ok' => 1]);
    }

    public function status300IsFailure(): void
    {
        $executor = $this->executor(new FakeHttpClient(statusCode: 300, body: 'redirect'));

        Expect::exception(OperationFailedException::class);

        $executor->execute($this->operation('getBlogTags'), []);
    }

    public function longErrorBodyIsTruncatedWithEllipsis(): void
    {
        // неоднородный префикс: смещение/удаление substr должно менять результат
        $executor = $this->executor(new FakeHttpClient(statusCode: 500, body: 'X' . str_repeat('a', 2_000)));

        $caught = null;

        try {
            $executor->execute($this->operation('getBlogTags'), []);
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('X' . str_repeat('a', 1_999) . '…');
    }

    public function errorBodyAtTheLimitIsNotTruncated(): void
    {
        $executor = $this->executor(new FakeHttpClient(statusCode: 500, body: str_repeat('b', 2_000)));

        $caught = null;

        try {
            $executor->execute($this->operation('getBlogTags'), []);
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains(str_repeat('b', 2_000));
        Assert::false(str_contains($caught->getMessage(), '…'));
    }

    public function missingQueryArgumentDoesNotStopLaterOnes(): void
    {
        $client = new FakeHttpClient();
        $operation = $this->multiQueryOperation();

        $this->executor($client)->execute($operation, ['second' => 'B']);

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/multi?second=B');
    }

    public function scalarArgumentsAreStringifiedExactly(): void
    {
        $client = new FakeHttpClient();

        $this->executor($client)->execute($this->multiQueryOperation(), [
            'first' => 5,
            'second' => 1.5,
            'flag' => true,
            'off' => false,
        ]);

        Assert::same(
            (string) $client->lastRequest?->getUri(),
            'https://api.test/multi?first=5&second=1.5&flag=true&off=false',
        );
    }

    private function multiQueryOperation(): \Rasuvaeff\Yii3Mcp\OpenApi\Operation
    {
        return (new SpecIndex([
            'paths' => ['/multi' => ['get' => [
                'operationId' => 'multiOp',
                'parameters' => [
                    ['name' => 'first', 'in' => 'query'],
                    ['name' => 'second', 'in' => 'query'],
                    ['name' => 'flag', 'in' => 'query'],
                    ['name' => 'off', 'in' => 'query'],
                ],
            ]]],
        ]))->get('multiOp');
    }

    /**
     * @param array<string, string> $headers
     */
    private function executor(FakeHttpClient $client, array $headers = []): HttpOperationExecutor
    {
        $factory = new Psr17Factory();

        return new HttpOperationExecutor(
            httpClient: $client,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
            defaultHeaders: $headers,
        );
    }

    private function operation(string $operationId): \Rasuvaeff\Yii3Mcp\OpenApi\Operation
    {
        return (new SpecIndex(OpenApiFixture::spec()))->get($operationId);
    }
}
