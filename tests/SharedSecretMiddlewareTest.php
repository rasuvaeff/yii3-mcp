<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeHandler;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(SharedSecretMiddleware::class)]
final class SharedSecretMiddlewareTest
{
    public function validSecretPassesThrough(): void
    {
        $handler = new FakeHandler();
        $request = new ServerRequest('POST', '/mcp', ['X-Mcp-Secret' => 's3cret']);

        $response = $this->middleware()->process($request, $handler);

        Assert::same($response->getStatusCode(), 200);
        Assert::notNull($handler->handledRequest);
    }

    public function invalidSecretIsRejected(): void
    {
        $handler = new FakeHandler();
        $request = new ServerRequest('POST', '/mcp', ['X-Mcp-Secret' => 'wrong']);

        $response = $this->middleware()->process($request, $handler);

        Assert::same($response->getStatusCode(), 401);
        Assert::null($handler->handledRequest);
        Assert::string((string) $response->getBody())->contains('X-Mcp-Secret');
    }

    public function missingHeaderIsRejected(): void
    {
        $response = $this->middleware()->process(new ServerRequest('POST', '/mcp'), new FakeHandler());

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

        Assert::same($middleware->process($request, new FakeHandler())->getStatusCode(), 200);
    }

    public function emptySecretRejectsEveryRequestWithClearExplanation(): void
    {
        $middleware = new SharedSecretMiddleware(secret: '', responseFactory: new Psr17Factory());
        $handler = new FakeHandler();

        $response = $middleware->process(new ServerRequest('POST', '/mcp', ['X-Mcp-Secret' => 'anything']), $handler);

        Assert::same($response->getStatusCode(), 503);
        Assert::null($handler->handledRequest);
        Assert::string((string) $response->getBody())->contains('endpoint_secret');
    }

    public function emptyHeaderNameThrows(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new SharedSecretMiddleware(secret: 's3cret', responseFactory: new Psr17Factory(), headerName: '');
    }

    private function middleware(): SharedSecretMiddleware
    {
        return new SharedSecretMiddleware(secret: 's3cret', responseFactory: new Psr17Factory());
    }
}
