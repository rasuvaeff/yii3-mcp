<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\OperationFailedException;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentity;
use Rasuvaeff\Yii3Mcp\OpenApi\HttpOperationExecutor;
use Rasuvaeff\Yii3Mcp\OpenApi\Operation;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Yii3Mcp\Tests\Support\EndlessStream;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeHttpClient;
use Rasuvaeff\Yii3Mcp\Tests\Support\IdentityDelegatedHeaderProvider;
use Rasuvaeff\Yii3Mcp\Tests\Support\MutableExecutionIdentityProvider;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Rasuvaeff\Yii3Mcp\Tests\Support\StreamBodyHttpClient;
use Rasuvaeff\Yii3Mcp\Tests\Support\StubStream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(HttpOperationExecutor::class)]
#[Covers(OperationFailedException::class)]
final class HttpOperationExecutorTest
{
    private const string SEGMENT_CHARSET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-.';
    private const string SEGMENT_LEAD_CHARSET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';

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
        /** @var array<string, mixed> $plan */
        $plan = json_decode((string) $result, associative: true, flags: JSON_THROW_ON_ERROR);
        Assert::false(array_key_exists('body', $plan));
    }

    public function dryRunPreviewOmitsTheBodyKeyWhenNoBodyArgumentWasPassed(): void
    {
        // "no body argument at all" and "a body argument explicitly null"
        // are different real-call outcomes (the latter still sends
        // Content-Type + a literal JSON "null" body) — the preview must
        // distinguish them by whether the "body" key is present at all,
        // not by showing null either way
        $client = new FakeHttpClient();

        $result = $this->executor($client)->execute(
            $this->operation('createSubscriber'),
            ['dryRun' => true],
            dryRunnable: true,
        );

        /** @var array<string, mixed> $plan */
        $plan = json_decode((string) $result, associative: true, flags: JSON_THROW_ON_ERROR);
        Assert::false(array_key_exists('body', $plan));
    }

    public function dryRunPreviewIncludesAnExplicitNullBodyArgument(): void
    {
        $client = new FakeHttpClient();

        $result = $this->executor($client)->execute(
            $this->operation('createSubscriber'),
            ['body' => null, 'dryRun' => true],
            dryRunnable: true,
        );

        /** @var array<string, mixed> $plan */
        $plan = json_decode((string) $result, associative: true, flags: JSON_THROW_ON_ERROR);
        Assert::true(array_key_exists('body', $plan));
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

    public function unparseableBaseUrlThrows(): void
    {
        // parse_url() returns false, not an array, for a genuinely malformed
        // URL — the credentials/query/fragment guards above must fail
        // closed here too, not silently skip themselves because there was
        // no array to read from
        $factory = new Psr17Factory();

        Expect::exception(InvalidArgumentException::class);

        new HttpOperationExecutor(
            httpClient: new FakeHttpClient(),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'http://example.com:notaport',
        );
    }

    private function multiQueryOperation(): Operation
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
    public function successResponseOverTheAdvertisedSizeIsRejected(): void
    {
        $factory = new Psr17Factory();
        $executor = new HttpOperationExecutor(
            httpClient: new FakeHttpClient(body: str_repeat('a', 200)),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
            maxResponseBytes: 100,
        );

        $caught = null;

        try {
            $executor->execute($this->operation('getBlogTags'), []);
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('100-byte limit');
    }

    public function unboundedChunkedResponseStopsReadingAtTheCap(): void
    {
        // a size-less (chunked) endless body: the executor must abandon the
        // read at the cap, not buffer until the worker dies
        $stream = new EndlessStream();
        $client = new readonly class ($stream) implements \Psr\Http\Client\ClientInterface {
            public function __construct(private EndlessStream $stream) {}

            #[\Override]
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new \Nyholm\Psr7\Response(200, [], $this->stream);
            }
        };

        $factory = new Psr17Factory();
        $executor = new HttpOperationExecutor(
            httpClient: $client,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
            maxResponseBytes: 10_000,
        );

        $caught = null;

        try {
            $executor->execute($this->operation('getBlogTags'), []);
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        // the read stopped right after crossing the cap — one chunk of slack,
        // never an unbounded buffer
        Assert::true($stream->servedBytes <= 10_000 + 8_192);
    }

    public function opaqueErrorsSuppressTheUpstreamErrorBody(): void
    {
        $factory = new Psr17Factory();
        $executor = new HttpOperationExecutor(
            httpClient: new FakeHttpClient(statusCode: 500, body: 'stack trace with internal hostnames'),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
            opaqueErrors: true,
        );

        $caught = null;

        try {
            $executor->execute($this->operation('getBlogTags'), []);
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('HTTP 500');
        Assert::false(str_contains($caught->getMessage(), 'stack trace'));
    }

    public function responseCapOfOneByteIsAValidConfiguration(): void
    {
        $factory = new Psr17Factory();
        $executor = new HttpOperationExecutor(
            httpClient: new FakeHttpClient(body: '1'),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
            maxResponseBytes: 1,
        );

        Assert::same($executor->execute($this->operation('getBlogTags'), []), 1);
    }

    public function advertisedOversizeIsRejectedWithoutReadingASingleByte(): void
    {
        // the stream THROWS on any read: the advertised-size rejection must
        // happen before a byte is pulled, or this raises a LogicException
        $factory = new Psr17Factory();
        $executor = new HttpOperationExecutor(
            httpClient: new StreamBodyHttpClient(new StubStream(content: 'x', advertisedSize: 101, throwOnRead: true)),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
            maxResponseBytes: 100,
        );

        $caught = null;

        try {
            $executor->execute($this->operation('getBlogTags'), []);
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('100-byte limit');
    }

    public function sizelessBodyExactlyAtTheCapSucceeds(): void
    {
        // chunked transfer (no advertised size), content EXACTLY at the cap:
        // the incremental read must accept it, not reject the boundary
        $body = '{"pad":"' . str_repeat('a', 90) . '"}';
        Assert::same(strlen($body), 100);

        $factory = new Psr17Factory();
        $executor = new HttpOperationExecutor(
            httpClient: new StreamBodyHttpClient(new StubStream(content: $body)),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
            maxResponseBytes: 100,
        );

        Assert::same($executor->execute($this->operation('getBlogTags'), []), ['pad' => str_repeat('a', 90)]);
    }

    public function advertisedBodyExactlyAtTheCapSucceeds(): void
    {
        $body = '{"pad":"' . str_repeat('b', 90) . '"}';
        $factory = new Psr17Factory();
        $executor = new HttpOperationExecutor(
            httpClient: new FakeHttpClient(body: $body),
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
            maxResponseBytes: 100,
        );

        Assert::same($executor->execute($this->operation('getBlogTags'), []), ['pad' => str_repeat('b', 90)]);
    }

    public function oversizedNonUtf8ErrorBodyReportsBytesActuallyRead(): void
    {
        // the error-path read is capped at excerpt size + 1 — the byte count
        // in the placeholder reports what was read, marked as a lower bound
        $executor = $this->executor(new FakeHttpClient(statusCode: 500, body: str_repeat("\xFF", 2_002)));

        $caught = null;

        try {
            $executor->execute($this->operation('getBlogTags'), []);
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('<non-UTF-8 response body, over 2001 bytes>');
    }

    public function oversizedErrorBodyKeepsItsPrefixInTheExcerpt(): void
    {
        // an advertised size over the excerpt cap must not blank the excerpt
        // — the first bytes of the error page are the diagnostic value
        $executor = $this->executor(new FakeHttpClient(statusCode: 500, body: str_repeat('e', 5_000)));

        $caught = null;

        try {
            $executor->execute($this->operation('getBlogTags'), []);
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())
            ->contains(str_repeat('e', 100))
            ->contains('…');
    }

    public function consumedSeekableBodyIsRewoundBeforeReading(): void
    {
        // a PSR-7 body may arrive already consumed (a middleware logged it);
        // unlike a (string) cast, read() does not rewind by itself
        $client = new class implements \Psr\Http\Client\ClientInterface {
            #[\Override]
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $response = new \Nyholm\Psr7\Response(200, [], '{"ok":true}');
                $response->getBody()->getContents(); // drain to EOF

                return $response;
            }
        };

        $result = $this->executor(new FakeHttpClient())->execute($this->operation('getBlogTags'), []);
        Assert::same($result, ['ok' => true]);

        $factory = new Psr17Factory();
        $executor = new HttpOperationExecutor(
            httpClient: $client,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
        );

        Assert::same($executor->execute($this->operation('getBlogTags'), []), ['ok' => true]);
    }

    public function overlyNestedJsonFallsBackToTheRawString(): void
    {
        // json_decode is capped at depth 128; a deeper (still bounded) body
        // is returned raw instead of recursing further
        $body = str_repeat('[', 200) . str_repeat(']', 200);
        $executor = $this->executor(new FakeHttpClient(body: $body));

        $result = $executor->execute($this->operation('getBlogTags'), []);

        Assert::same($result, $body);
    }

    public function optedInPathArgumentCarriesSeparatorsPercentEncoded(): void
    {
        $client = new FakeHttpClient();

        $this->multiSegmentExecutor($client, ['slug' => 3])
            ->execute($this->operation('getBlogTagBySlug'), ['slug' => 'dev/keppio']);

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/rest/blog-tag/dev%2Fkeppio');
    }

    public function optedInPathArgumentStillAcceptsASingleSegment(): void
    {
        $client = new FakeHttpClient();

        $this->multiSegmentExecutor($client, ['slug' => 3])
            ->execute($this->operation('getBlogTagBySlug'), ['slug' => '122']);

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/rest/blog-tag/122');
    }

    /**
     * The opt-in buys the separator and nothing else: everything
     * {@see self::routeEscapingPathArgumentProvider()} rejects for a plain
     * parameter — except the bare separator — stays rejected here too.
     */
    #[DataProvider('optedInRejectedPathArgumentProvider')]
    public function optedInPathArgumentIsStillValidated(string $value): void
    {
        $client = new FakeHttpClient();
        $caught = null;

        try {
            $this->multiSegmentExecutor($client, ['slug' => 3])
                ->execute($this->operation('getBlogTagBySlug'), ['slug' => $value]);
        } catch (InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($client->requestCount, 0);
    }

    public static function optedInRejectedPathArgumentProvider(): iterable
    {
        yield 'dot segment' => ['..'];
        yield 'current directory' => ['.'];
        yield 'empty' => [''];
        yield 'compound traversal' => ['../..'];
        yield 'traversal after a segment' => ['x/..'];
        yield 'traversal inside a segment' => ['a..b'];
        yield 'backslash' => ['a\\b'];
        yield 'empty segment' => ['dev//keppio'];
        yield 'leading separator' => ['/keppio'];
        yield 'trailing separator' => ['dev/'];
        yield 'current directory segment' => ['dev/./keppio'];
        yield 'over the segment limit' => ['1/repository/archive/x'];
        yield 'out-of-charset segment' => ['dev/kep pio'];
        yield 'segment starting with a dot' => ['dev/.keppio'];
        yield 'segment starting with a dash' => ['dev/-keppio'];
    }

    public function segmentLimitOfOneKeepsSingleSegmentBehaviour(): void
    {
        $client = new FakeHttpClient();
        $executor = $this->multiSegmentExecutor($client, ['slug' => 1]);
        $caught = null;

        try {
            $executor->execute($this->operation('getBlogTagBySlug'), ['slug' => 'dev/keppio']);
        } catch (InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);

        $executor->execute($this->operation('getBlogTagBySlug'), ['slug' => 'v1.2']);

        Assert::same((string) $client->lastRequest?->getUri(), 'https://api.test/rest/blog-tag/v1.2');
    }

    public function segmentLimitAtTheCeilingIsAccepted(): void
    {
        $client = new FakeHttpClient();
        $value = implode('/', array_fill(0, 20, 'a'));

        $this->multiSegmentExecutor($client, ['slug' => 20])
            ->execute($this->operation('getBlogTagBySlug'), ['slug' => $value]);

        Assert::same(
            (string) $client->lastRequest?->getUri(),
            'https://api.test/rest/blog-tag/' . str_repeat('a%2F', 19) . 'a',
        );
    }

    /**
     * The limits arrive from application params, where nothing enforces the
     * shape. A numeric string is the dangerous one: it passes every ordering
     * comparison, then fails `=== 1` in buildPath() — silently switching the
     * multi-segment path on for a parameter configured as single-segment.
     */
    #[DataProvider('invalidSegmentLimitProvider')]
    public function invalidSegmentLimitThrows(mixed $limit): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->multiSegmentExecutor(new FakeHttpClient(), ['slug' => $limit]);
    }

    public function invalidSegmentLimitNamesTheOffendingValue(): void
    {
        $caught = null;

        try {
            $this->multiSegmentExecutor(new FakeHttpClient(), ['slug' => 21]);
        } catch (InvalidArgumentException $caught) {
        }

        Assert::same(
            $caught?->getMessage(),
            'Segment limit for path parameter "slug" must be an integer between 1 and 20, 21 given',
        );
    }

    public function invalidSegmentLimitNamesTheOffendingType(): void
    {
        $caught = null;

        try {
            $this->multiSegmentExecutor(new FakeHttpClient(), ['slug' => '3']);
        } catch (InvalidArgumentException $caught) {
        }

        Assert::same(
            $caught?->getMessage(),
            'Segment limit for path parameter "slug" must be an integer between 1 and 20, string given',
        );
    }

    public static function invalidSegmentLimitProvider(): iterable
    {
        yield 'below one' => [0];
        yield 'negative' => [-1];
        yield 'above the ceiling' => [21];
        yield 'numeric string within range' => ['3'];
        yield 'numeric string of one' => ['1'];
        yield 'numeric string out of range' => ['21'];
        yield 'non-numeric string' => ['three'];
        yield 'float' => [3.0];
        yield 'bool' => [true];
        yield 'null' => [null];
        yield 'array' => [[3]];
    }

    /**
     * The rule is stated here independently of the executor's own regex —
     * a charset/anchor regression in {@see HttpOperationExecutor} fails this
     * property instead of moving in lockstep with it.
     */
    #[Property(runs: 400, timeoutMs: 250)]
    public function optedInPathArgumentAcceptsExactlyWellFormedSegmentPaths(string $value): void
    {
        $segments = explode('/', $value);
        $wellFormed = $value !== ''
            && !str_contains($value, '..')
            && !str_contains($value, '\\')
            && count($segments) <= 3
            && array_reduce(
                $segments,
                static fn(bool $carry, string $segment): bool => $carry
                    && $segment !== ''
                    && strspn($segment, self::SEGMENT_CHARSET) === strlen($segment)
                    && strspn($segment[0], self::SEGMENT_LEAD_CHARSET) === 1,
                true,
            );

        Classify::cover($wellFormed, 'accepted path', 15.0);
        Classify::cover(!$wellFormed, 'rejected path', 15.0);
        Classify::when(count($segments) > 1, 'multi-segment');
        Classify::when(count($segments) > 3, 'over the segment limit');

        $client = new FakeHttpClient();
        $accepted = true;

        try {
            $this->multiSegmentExecutor($client, ['slug' => 3])
                ->execute($this->operation('getBlogTagBySlug'), ['slug' => $value]);
        } catch (InvalidArgumentException) {
            $accepted = false;
        }

        Assert::same($accepted, $wellFormed);
        Assert::same($client->requestCount, $wellFormed ? 1 : 0);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function optedInPathArgumentAcceptsExactlyWellFormedSegmentPathsGenerators(): array
    {
        return [
            'value' => Gen::frequency([
                [3, Gen::stringFrom('abz09_/', 0, 18)],
                [2, Gen::stringFrom('abz09_-./\\ ', 0, 18)],
                [1, Gen::stringAscii()],
            ]),
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function optedInPathArgumentAcceptsExactlyWellFormedSegmentPathsExamples(): iterable
    {
        yield 'gitlab namespace path' => ['dev/keppio'];
        yield 'nested subgroup' => ['a/b/c'];
        yield 'numeric id' => ['122'];
        yield 'dotted single segment' => ['v1.2'];
        yield 'traversal inside a segment' => ['a..b'];
        yield 'traversal segment' => ['x/..'];
        yield 'empty segment' => ['a//b'];
        yield 'over the limit' => ['a/b/c/d'];
        yield 'backslash' => ['a\\b'];
        yield 'leading dash segment' => ['a/-b'];
    }

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

    private function operation(string $operationId): Operation
    {
        return (new SpecIndex(OpenApiFixture::spec()))->get($operationId);
    }

    /**
     * @param array<array-key, mixed> $multiSegmentPathParams
     */
    private function multiSegmentExecutor(FakeHttpClient $client, array $multiSegmentPathParams): HttpOperationExecutor
    {
        $factory = new Psr17Factory();

        return new HttpOperationExecutor(
            httpClient: $client,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
            multiSegmentPathParams: $multiSegmentPathParams,
        );
    }
}
