<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Captor;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Mcp\Identity\StaticSecretResolver;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(SharedSecretMiddleware::class)]
final class SharedSecretMiddlewareTest
{
    public function validSecretPassesThrough(): void
    {
        [$handler] = $this->handler();
        $request = new ServerRequest('POST', '/mcp', ['X-Mcp-Secret' => 's3cret']);

        $response = $this->middleware()->process($request, $handler);

        Assert::same($response->getStatusCode(), 200);
        verify(fn() => $handler->handle(Arg::any()));
    }

    public function singleSecretAttributesTheDefaultClientId(): void
    {
        [$handler, $requests] = $this->handler();
        $request = new ServerRequest('POST', '/mcp', ['X-Mcp-Secret' => 's3cret']);

        $this->middleware()->process($request, $handler);

        Assert::same(
            $requests->last()->getAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE),
            SharedSecretMiddleware::DEFAULT_CLIENT_ID,
        );
    }

    public function resolverAttributesTheOwningClientId(): void
    {
        $middleware = new SharedSecretMiddleware(
            secret: '',
            responseFactory: new Psr17Factory(),
            resolver: new StaticSecretResolver(['ci' => 'ci-secret', 'claude' => ['old-secret', 'new-secret']]),
        );
        [$handler, $requests] = $this->handler();

        $response = $middleware->process(new ServerRequest('POST', '/mcp', ['X-Mcp-Secret' => 'old-secret']), $handler);

        Assert::same($response->getStatusCode(), 200);
        Assert::same($requests->last()->getAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE), 'claude');
    }

    public function resolverRejectsARevokedSecret(): void
    {
        $middleware = new SharedSecretMiddleware(
            secret: '',
            responseFactory: new Psr17Factory(),
            resolver: new StaticSecretResolver(['claude' => 'new-secret']),
        );
        [$handler] = $this->handler();

        $response = $middleware->process(new ServerRequest('POST', '/mcp', ['X-Mcp-Secret' => 'old-secret']), $handler);

        Assert::same($response->getStatusCode(), 401);
        verify(fn() => $handler->handle(Arg::any()), never: true);
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function rejectsASecretAndAResolverTogether(): void
    {
        new SharedSecretMiddleware(
            secret: 's3cret',
            responseFactory: new Psr17Factory(),
            resolver: new StaticSecretResolver(['claude' => 'other']),
        );
    }

    public function invalidSecretIsRejected(): void
    {
        [$handler] = $this->handler();
        $request = new ServerRequest('POST', '/mcp', ['X-Mcp-Secret' => 'wrong']);

        $response = $this->middleware()->process($request, $handler);

        Assert::same($response->getStatusCode(), 401);
        verify(fn() => $handler->handle(Arg::any()), never: true);
        Assert::string((string) $response->getBody())->contains('X-Mcp-Secret');
    }

    public function missingHeaderIsRejected(): void
    {
        [$handler] = $this->handler();

        $response = $this->middleware()->process(new ServerRequest('POST', '/mcp'), $handler);

        Assert::same($response->getStatusCode(), 401);
    }

    public function customHeaderNameIsHonored(): void
    {
        $middleware = new SharedSecretMiddleware(
            secret: 's3cret',
            responseFactory: new Psr17Factory(),
            headerName: 'X-Custom-Auth',
        );
        $request = new ServerRequest('POST', '/mcp', ['X-Custom-Auth' => 's3cret']);
        [$handler] = $this->handler();

        Assert::same($middleware->process($request, $handler)->getStatusCode(), 200);
    }

    public function emptySecretRejectsEveryRequestWithClearExplanation(): void
    {
        $middleware = new SharedSecretMiddleware(secret: '', responseFactory: new Psr17Factory());
        [$handler] = $this->handler();

        $response = $middleware->process(new ServerRequest('POST', '/mcp', ['X-Mcp-Secret' => 'anything']), $handler);

        Assert::same($response->getStatusCode(), 503);
        verify(fn() => $handler->handle(Arg::any()), never: true);
        Assert::string((string) $response->getBody())->contains('endpoint_secret');
    }

    public function emptyHeaderNameThrows(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new SharedSecretMiddleware(secret: 's3cret', responseFactory: new Psr17Factory(), headerName: '');
    }

    /**
     * A pass-through handler double: every request it serves is captured for
     * attribute assertions, and a 200 confirms the middleware delegated.
     *
     * @return array{RequestHandlerInterface, Captor<ServerRequestInterface>}
     */
    private function handler(): array
    {
        $handler = Understudy::for(RequestHandlerInterface::class);
        $requests = Arg::captor(ServerRequestInterface::class);
        when(fn() => $handler->handle($requests->capture()))->returns(new Response(200));

        return [$handler, $requests];
    }

    private function middleware(): SharedSecretMiddleware
    {
        return new SharedSecretMiddleware(secret: 's3cret', responseFactory: new Psr17Factory());
    }
}
