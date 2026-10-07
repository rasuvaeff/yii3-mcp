<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\InvalidToolArgumentException;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\OperationFailedException;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentity;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionRequestAttributesInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\InProcessScopeInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\Operation;
use Rasuvaeff\Yii3Mcp\OpenApi\OperationResponseDecoder;
use Rasuvaeff\Yii3Mcp\OpenApi\Psr15OperationExecutor;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Yii3Mcp\Tests\Support\CountingIdentityProvider;
use Rasuvaeff\Yii3Mcp\Tests\Support\IdentityDelegatedHeaderProvider;
use Rasuvaeff\Yii3Mcp\Tests\Support\OpenApiFixture;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingRequestHandler;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingScope;
use Rasuvaeff\Yii3Mcp\Tests\Support\StubStream;
use Rasuvaeff\Yii3Mcp\Tests\Support\SubjectRequestAttributes;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(Psr15OperationExecutor::class)]
#[Covers(OperationFailedException::class)]
#[Covers(InvalidToolArgumentException::class)]
final class Psr15OperationExecutorTest
{
    public function buildsTheSameRequestTheHttpExecutorWouldSend(): void
    {
        $handler = new RecordingRequestHandler();

        $result = $this->executor($handler)->execute($this->operation('getBlogTags'), ['locale' => 'en']);

        $request = $handler->requests[0];

        Assert::same($request->getMethod(), 'GET');
        Assert::same((string) $request->getUri(), 'https://api.test/rest/blog-tags?locale=en');
        Assert::same($request->getHeaderLine('Accept'), 'application/json');
        Assert::same($result, ['ok' => true]);
    }

    public function appliesDefaultHeaders(): void
    {
        $handler = new RecordingRequestHandler();

        $this->executor($handler, headers: ['Authorization' => 'Bearer token-1'])
            ->execute($this->operation('getBlogTags'), []);

        Assert::same($handler->requests[0]->getHeaderLine('Authorization'), 'Bearer token-1');
    }

    public function substitutesAndEncodesPathParameters(): void
    {
        $handler = new RecordingRequestHandler();

        $this->executor($handler)->execute($this->operation('getBlogTagBySlug'), ['slug' => 'a b+c?']);

        Assert::same((string) $handler->requests[0]->getUri(), 'https://api.test/rest/blog-tag/a%20b%2Bc%3F');
    }

    #[DataProvider('routeEscapingPathArgumentProvider')]
    public function routeEscapingPathArgumentIsRejected(string $slug): void
    {
        $handler = new RecordingRequestHandler();

        $caught = null;

        try {
            $this->executor($handler)->execute($this->operation('getBlogTagBySlug'), ['slug' => $slug]);
        } catch (InvalidToolArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::same(count($handler->requests), 0);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routeEscapingPathArgumentProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'dot' => ['.'];
        yield 'dot-dot run inside the value' => ['a..b'];
        yield 'separator' => ['a/b'];
        yield 'backslash' => ['a\\b'];
    }

    public function missingPathParameterThrows(): void
    {
        Expect::exception(InvalidToolArgumentException::class);

        $this->executor(new RecordingRequestHandler())->execute($this->operation('getBlogTagBySlug'), []);
    }

    public function sendsJsonRequestBody(): void
    {
        $handler = new RecordingRequestHandler();

        $this->executor($handler)->execute(
            $this->operation('createSubscriber'),
            ['body' => ['email' => 'user@example.com']],
        );

        $request = $handler->requests[0];

        Assert::same($request->getMethod(), 'POST');
        Assert::same($request->getHeaderLine('Content-Type'), 'application/json');
        Assert::same((string) $request->getBody(), '{"email":"user@example.com"}');
    }

    public function bodyArgumentIsIgnoredForBodylessOperations(): void
    {
        $handler = new RecordingRequestHandler();

        $this->executor($handler)->execute($this->operation('getBlogTags'), ['body' => ['x' => 1]]);

        Assert::same((string) $handler->requests[0]->getBody(), '');
        Assert::same($handler->requests[0]->getHeaderLine('Content-Type'), '');
    }

    public function delegatedHeadersAndAttributesComeFromOneIdentityResolution(): void
    {
        $provider = new CountingIdentityProvider(new ExecutionIdentity(subjectId: 'user-1', tenantId: 'tenant-a'));
        $handler = new RecordingRequestHandler();

        $this->executor(
            $handler,
            identityProvider: $provider,
            delegatedHeaderProvider: new IdentityDelegatedHeaderProvider(),
            requestAttributes: new SubjectRequestAttributes(),
        )->execute($this->operation('getBlogTags'), []);

        $request = $handler->requests[0];

        Assert::same($request->getHeaderLine('Authorization'), 'Bearer tenant-a:user-1');
        Assert::same($request->getHeaderLine('X-Upstream-Operation'), 'getBlogTags');
        Assert::same($request->getAttribute('current_user_id'), 'user-1');

        // one resolution feeds BOTH the headers and the attributes — a
        // provider that reads request state must not be called twice
        Assert::same($provider->calls, 1);
    }

    public function attributesMapperWithoutAnIdentityAddsNoAttributes(): void
    {
        $handler = new RecordingRequestHandler();

        $this->executor($handler, requestAttributes: new SubjectRequestAttributes())
            ->execute($this->operation('getBlogTags'), []);

        // static-header mode resolves no identity, so there is nothing to map
        Assert::null($handler->requests[0]->getAttribute('current_user_id'));
    }

    public function dryRunnableOperationWithoutTheFlagExecutesForReal(): void
    {
        // a dry-run-ENABLED operation called WITHOUT the dryRun argument is a
        // normal execution — a precedence slip in the flag check would turn
        // every such call into a preview and silently stop executing anything
        $handler = new RecordingRequestHandler();

        $result = $this->executor($handler)->execute(
            $this->operation('getBlogTags'),
            ['locale' => 'en'],
            dryRunnable: true,
        );

        Assert::same($result, ['ok' => true]);
        Assert::same(count($handler->requests), 1);
        Assert::same((string) $handler->requests[0]->getUri(), 'https://api.test/rest/blog-tags?locale=en');
    }

    public function anExplicitDryRunFalseExecutesForReal(): void
    {
        // `dryRun: false` is the caller actively declining the preview — the
        // only input that distinguishes the flag check from its precedence
        // mutant, which would preview exactly this call
        $handler = new RecordingRequestHandler();

        $result = $this->executor($handler)->execute(
            $this->operation('getBlogTags'),
            ['locale' => 'en', 'dryRun' => false],
            dryRunnable: true,
        );

        Assert::same($result, ['ok' => true]);
        Assert::same(count($handler->requests), 1);
    }

    public function dryRunArgumentOnANonDryRunnableOperationIsIgnored(): void
    {
        // the executor reached directly with a stray dryRun argument on an
        // operation that never opted in must still execute — the SDK's
        // schema validation keeps the argument out of real calls; this guard
        // keeps the executor's own failure direction safe
        $handler = new RecordingRequestHandler();

        $result = $this->executor($handler)->execute(
            $this->operation('getBlogTags'),
            ['locale' => 'en', 'dryRun' => true],
            dryRunnable: false,
        );

        Assert::same($result, ['ok' => true]);
        Assert::same(count($handler->requests), 1);
    }

    public function dryRunPreviewMirrorsTheHttpExecutorSemantics(): void
    {
        $handler = new RecordingRequestHandler();

        $preview = $this->executor($handler)->execute(
            $this->operation('createSubscriber'),
            ['dryRun' => true, 'body' => ['email' => 'user@example.com']],
            dryRunnable: true,
            toolName: 'subscribe',
        );

        Assert::json($preview)
            ->isObject()
            ->hasKeys('dryRun', 'operationId', 'method', 'url', 'body');

        // the preview never enters the application's handler
        Assert::same(count($handler->requests), 0);
    }

    public function dryRunPreviewOmitsBodyWhenNoneWouldBeSent(): void
    {
        $handler = new RecordingRequestHandler();

        $preview = $this->executor($handler)->execute(
            $this->operation('getBlogTags'),
            ['dryRun' => true],
            dryRunnable: true,
        );

        Assert::json($preview)
            ->isObject()
            ->hasKeys('dryRun', 'operationId', 'method', 'url');

        Assert::false(str_contains($preview, '"body"'));
    }

    public function nonBooleanDryRunFlagThrowsInsteadOfExecuting(): void
    {
        $handler = new RecordingRequestHandler();

        $caught = null;

        try {
            $this->executor($handler)->execute(
                $this->operation('getBlogTags'),
                ['dryRun' => 'yes'],
                dryRunnable: true,
            );
        } catch (InvalidToolArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::same(count($handler->requests), 0);
    }

    public function nonSuccessResponseThrowsWithTheExcerpt(): void
    {
        $handler = new RecordingRequestHandler(statusCode: 422, body: '{"error":"validation"}');

        $caught = null;

        try {
            $this->executor($handler)->execute($this->operation('getBlogTags'), []);
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('HTTP 422')->contains('validation');
    }

    public function opaqueErrorsSuppressTheExcerpt(): void
    {
        $handler = new RecordingRequestHandler(statusCode: 500, body: 'internal detail');

        $caught = null;

        try {
            $this->executor($handler, opaqueErrors: true)->execute($this->operation('getBlogTags'), []);
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), 'Tool "getBlogTags" failed with HTTP 500');
    }

    public function nonJsonResponseIsReturnedAsString(): void
    {
        $handler = new RecordingRequestHandler(body: 'plain text');

        Assert::same($this->executor($handler)->execute($this->operation('getBlogTags'), []), 'plain text');
    }

    public function advertisedBodyOverTheCapIsRefusedWithoutReading(): void
    {
        // the stream THROWS on any read: the advertised-size rejection must
        // happen before a single byte is buffered
        $handler = Understudy::for(RequestHandlerInterface::class);
        when(fn() => $handler->handle(Arg::any()))
            ->returns(new Response(200, [], new StubStream(content: 'x', advertisedSize: 101, throwOnRead: true)));

        $caught = null;

        try {
            $this->executor($handler, maxResponseBytes: 100)->execute($this->operation('getBlogTags'), []);
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('exceeds');
    }

    public function emptyBaseUrlThrows(): void
    {
        $factory = new Psr17Factory();

        Expect::exception(InvalidArgumentException::class);

        new Psr15OperationExecutor(
            handler: new RecordingRequestHandler(),
            serverRequestFactory: $factory,
            streamFactory: $factory,
            baseUrl: '  ',
        );
    }

    public function scopeWrapsEveryNestedCall(): void
    {
        $scope = new RecordingScope();
        $handler = new RecordingRequestHandler();
        $executor = $this->executor($handler, scope: $scope);

        $executor->execute($this->operation('getBlogTags'), []);
        $executor->execute($this->operation('getBlogTags'), []);

        // two nested calls in one process: enter/leave balanced per call, no
        // state leakage of one call's scope into the next
        Assert::same($scope->log, ['enter', 'leave', 'enter', 'leave']);
        Assert::same(count($handler->requests), 2);
    }

    public function leaveRunsEvenWhenTheHandlerThrows(): void
    {
        $scope = new RecordingScope();
        $handler = Understudy::for(RequestHandlerInterface::class);
        when(fn() => $handler->handle(Arg::any()))->throws(new RuntimeException('re-entry'));
        $executor = $this->executor($handler, scope: $scope);

        $caught = null;

        try {
            $executor->execute($this->operation('getBlogTags'), []);
        } catch (RuntimeException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($scope->log, ['enter', 'leave']);
    }

    public function servedToolNameNamesFailures(): void
    {
        $handler = new RecordingRequestHandler(statusCode: 500, body: 'boom');

        $caught = null;

        try {
            $this->executor($handler)->execute($this->operation('getBlogTags'), [], toolName: 'blog_tags');
        } catch (OperationFailedException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('Tool "blog_tags"');
    }

    /**
     * @param array<string, string> $headers
     * @param array<array-key, mixed> $multiSegmentPathParams
     */
    private function executor(
        RequestHandlerInterface $handler,
        array $headers = [],
        ?CountingIdentityProvider $identityProvider = null,
        ?IdentityDelegatedHeaderProvider $delegatedHeaderProvider = null,
        ?ExecutionRequestAttributesInterface $requestAttributes = null,
        ?InProcessScopeInterface $scope = null,
        bool $opaqueErrors = false,
        int $maxResponseBytes = OperationResponseDecoder::DEFAULT_MAX_RESPONSE_BYTES,
        array $multiSegmentPathParams = [],
    ): Psr15OperationExecutor {
        $factory = new Psr17Factory();

        return new Psr15OperationExecutor(
            handler: $handler,
            serverRequestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://api.test/',
            defaultHeaders: $headers,
            identityProvider: $identityProvider,
            delegatedHeaderProvider: $delegatedHeaderProvider,
            requestAttributes: $requestAttributes,
            inProcessScope: $scope,
            maxResponseBytes: $maxResponseBytes,
            opaqueErrors: $opaqueErrors,
            multiSegmentPathParams: $multiSegmentPathParams,
        );
    }

    private function operation(string $operationId): Operation
    {
        return (new SpecIndex(OpenApiFixture::spec()))->get($operationId);
    }
}
