<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\OperationFailedException;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentity;
use Rasuvaeff\Yii3Mcp\OpenApi\HttpOperationExecutor;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeHttpClient;
use Rasuvaeff\Yii3Mcp\Tests\Support\IdentityDelegatedHeaderProvider;
use Rasuvaeff\Yii3Mcp\Tests\Support\MutableExecutionIdentityProvider;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
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

        // a separator inside the value is rejected outright (see
        // routeEscapingPathArgumentProvider), so encoding is exercised with
        // the reserved characters that stay legal
        $this->executor($client)->execute($this->operation('getBlogTagBySlug'), ['slug' => 'a b+c?']);

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/rest/blog-tag/a%20b%2Bc%3F');
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

    public function delegatedHeadersAreResolvedForEveryIdentity(): void
    {
        $client = new FakeHttpClient();
        $identityProvider = new MutableExecutionIdentityProvider(new ExecutionIdentity(subjectId: 'user-1', tenantId: 'tenant-a'));
        $factory = new Psr17Factory();
        $executor = new HttpOperationExecutor(
            httpClient: $client,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
            defaultHeaders: ['Authorization' => 'Bearer broad-service-token'],
            identityProvider: $identityProvider,
            delegatedHeaderProvider: new IdentityDelegatedHeaderProvider(),
        );

        $executor->execute($this->operation('getBlogTags'), []);
        Assert::same($client->lastRequest?->getHeaderLine('Authorization'), 'Bearer tenant-a:user-1');

        $identityProvider->identity = new ExecutionIdentity(subjectId: 'user-2', tenantId: 'tenant-b');
        $executor->execute($this->operation('getBlogTags'), []);

        Assert::same($client->lastRequest?->getHeaderLine('Authorization'), 'Bearer tenant-b:user-2');
        Assert::same($client->lastRequest?->getHeaderLine('X-Upstream-Operation'), 'getBlogTags');
    }

    public function nonOverriddenDefaultHeadersSurviveDelegation(): void
    {
        // delegated headers REPLACE matching defaults, they do not evict
        // the rest of the default header set
        $client = new FakeHttpClient();
        $factory = new Psr17Factory();
        $executor = new HttpOperationExecutor(
            httpClient: $client,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
            defaultHeaders: ['Authorization' => 'Bearer broad-service-token', 'X-Fixed' => 'kept'],
            identityProvider: new MutableExecutionIdentityProvider(new ExecutionIdentity(subjectId: 'user-1', tenantId: 'tenant-a')),
            delegatedHeaderProvider: new IdentityDelegatedHeaderProvider(),
        );

        $executor->execute($this->operation('getBlogTags'), []);

        Assert::same($client->lastRequest?->getHeaderLine('Authorization'), 'Bearer tenant-a:user-1');
        Assert::same($client->lastRequest?->getHeaderLine('X-Fixed'), 'kept');
    }

    public function delegatedProviderFailureIsFailClosedBeforeHttp(): void
    {
        $client = new FakeHttpClient();
        $factory = new Psr17Factory();
        $executor = new HttpOperationExecutor(
            httpClient: $client,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
            identityProvider: new MutableExecutionIdentityProvider(new ExecutionIdentity()),
            delegatedHeaderProvider: new IdentityDelegatedHeaderProvider(),
        );

        $caught = null;

        try {
            $executor->execute($this->operation('getBlogTags'), []);
        } catch (\RuntimeException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($client->requestCount, 0);
    }

    public function delegatedProvidersMustBeConfiguredAsAPair(): void
    {
        $factory = new Psr17Factory();

        Expect::exception(InvalidArgumentException::class);

        new HttpOperationExecutor(
            httpClient: new FakeHttpClient(),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
            identityProvider: new MutableExecutionIdentityProvider(new ExecutionIdentity()),
        );
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

    public function longMultiByteErrorBodyStaysEncodable(): void
    {
        // 2800 bytes of two-byte characters: the 2000-byte cut lands inside a
        // character, and a broken sequence would make the SDK fail to encode
        // the tool-error envelope — silently dropping the whole response
        $executor = $this->executor(new FakeHttpClient(statusCode: 500, body: str_repeat('привет ', 400)));

        $caught = null;

        try {
            $executor->execute($this->operation('getBlogTags'), []);
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::same(preg_match('//u', $caught->getMessage()), 1);
        json_encode($caught->getMessage(), JSON_THROW_ON_ERROR);
        Assert::string($caught->getMessage())->contains('…');
    }

    public function nonUtf8ErrorBodyIsReplacedWithAPlaceholder(): void
    {
        // an upstream error page in a legacy encoding is unencodable however
        // it is cut, so the excerpt is dropped in favour of its byte count
        $executor = $this->executor(new FakeHttpClient(statusCode: 500, body: "\xEF\xF0\xE8\xE2\xE5\xF2"));

        $caught = null;

        try {
            $executor->execute($this->operation('getBlogTags'), []);
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::same(preg_match('//u', $caught->getMessage()), 1);
        Assert::string($caught->getMessage())->contains('<non-UTF-8 response body, 6 bytes>');
    }

    public function nullQueryArgumentIsSkipped(): void
    {
        $client = new FakeHttpClient();

        $this->executor($client)->execute($this->multiQueryOperation(), ['first' => null, 'second' => 'B']);

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/multi?second=B');
    }

    public function nullPathArgumentThrowsAsIfMissing(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->executor(new FakeHttpClient())->execute($this->operation('getBlogTagBySlug'), ['slug' => null]);
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

    public function dryRunReturnsThePlannedRequestWithoutCallingHttp(): void
    {
        $client = new FakeHttpClient();

        $result = $this->executor($client)->execute(
            $this->operation('createSubscriber'),
            ['body' => ['email' => 'user@example.com'], 'dryRun' => true],
            dryRunnable: true,
        );

        Assert::same($client->requestCount, 0);
        /** @var array{dryRun: bool, operationId: string, method: string, url: string, body: mixed} $plan */
        $plan = json_decode((string) $result, associative: true, flags: JSON_THROW_ON_ERROR);
        Assert::true($plan['dryRun']);
        Assert::same($plan['operationId'], 'createSubscriber');
        Assert::same($plan['method'], 'POST');
        Assert::string($plan['url'])->contains('https://api.test/');
        Assert::same($plan['body'], ['email' => 'user@example.com']);
    }

    public function dryRunPreviewOmitsAStrayBodyOnABodylessOperation(): void
    {
        $client = new FakeHttpClient();

        // the real call ignores `body` on a bodyless operation — the
        // preview must not claim it would be sent
        $result = $this->executor($client)->execute(
            $this->operation('getBlogTags'),
            ['body' => ['x' => 1], 'dryRun' => true],
            dryRunnable: true,
        );

        Assert::same($client->requestCount, 0);
        /** @var array{body: mixed} $plan */
        $plan = json_decode((string) $result, associative: true, flags: JSON_THROW_ON_ERROR);
        Assert::null($plan['body']);
    }

    public function dryRunFlagIsIgnoredWhenTheOperationIsNotDryRunnable(): void
    {
        $client = new FakeHttpClient();

        $this->executor($client)->execute(
            $this->operation('getBlogTags'),
            ['dryRun' => true],
            dryRunnable: false,
        );

        Assert::same($client->requestCount, 1);
    }

    public function dryRunnableOperationExecutesNormallyWithoutTheFlag(): void
    {
        $client = new FakeHttpClient();

        $this->executor($client)->execute($this->operation('getBlogTags'), [], dryRunnable: true);

        Assert::same($client->requestCount, 1);
    }

    public function nonBooleanDryRunIsRejectedNotExecutedForReal(): void
    {
        $client = new FakeHttpClient();

        // a truthy-but-not-boolean value (e.g. from a lenient client) must
        // never fall through to a REAL call the caller meant to preview —
        // fail safe with an error instead
        $caught = null;

        try {
            $this->executor($client)->execute($this->operation('getBlogTags'), ['dryRun' => 1], dryRunnable: true);
        } catch (InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($client->requestCount, 0);
    }

    public function dryRunFalseExecutesForReal(): void
    {
        $client = new FakeHttpClient();

        $this->executor($client)->execute($this->operation('getBlogTags'), ['dryRun' => false], dryRunnable: true);

        Assert::same($client->requestCount, 1);
    }

    public function nonBooleanDryRunIsStillIgnoredWhenTheOperationIsNotDryRunnable(): void
    {
        $client = new FakeHttpClient();

        // for a non-dry-runnable operation the argument is undeclared noise,
        // exactly like `dryRun: true` — not a reason to reject the call
        $this->executor($client)->execute($this->operation('getBlogTags'), ['dryRun' => 1], dryRunnable: false);

        Assert::same($client->requestCount, 1);
    }

    #[DataProvider('routeEscapingPathArgumentProvider')]
    public function routeEscapingPathArgumentIsRejected(string $value): void
    {
        $client = new FakeHttpClient();
        $caught = null;

        try {
            $this->executor($client)->execute($this->operation('getBlogTagBySlug'), ['slug' => $value]);
        } catch (InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($client->requestCount, 0);
    }

    public static function routeEscapingPathArgumentProvider(): iterable
    {
        // rawurlencode leaves "." verbatim and encodes "/" as %2F, which
        // upstreams decoding before path normalization hand back as a real
        // separator — so containing ".." or a separator is as dangerous as
        // being one
        yield 'dot segment' => ['..'];
        yield 'current directory' => ['.'];
        yield 'empty' => [''];
        yield 'compound traversal' => ['../..'];
        yield 'traversal after a segment' => ['x/..'];
        yield 'backslash traversal' => ['..\\..'];
        yield 'plain separator' => ['a/b'];
        yield 'plain backslash' => ['a\\b'];
        yield 'trailing traversal' => ['tag..'];
    }

    public function pathArgumentContainingDotsIsNotADotSegment(): void
    {
        $client = new FakeHttpClient();

        $this->executor($client)->execute($this->operation('getBlogTagBySlug'), ['slug' => 'v1.2']);

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/rest/blog-tag/v1.2');
    }

    public function baseUrlWithEmbeddedCredentialsThrows(): void
    {
        $factory = new Psr17Factory();

        Expect::exception(InvalidArgumentException::class);

        new HttpOperationExecutor(
            httpClient: new FakeHttpClient(),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://svc:secret@api.test/',
        );
    }

    public function baseUrlWithQueryStringThrows(): void
    {
        $factory = new Psr17Factory();

        Expect::exception(InvalidArgumentException::class);

        new HttpOperationExecutor(
            httpClient: new FakeHttpClient(),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/?api_key=secret',
        );
    }

    public function baseUrlWithFragmentThrows(): void
    {
        $factory = new Psr17Factory();

        Expect::exception(InvalidArgumentException::class);

        new HttpOperationExecutor(
            httpClient: new FakeHttpClient(),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/#fragment',
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
