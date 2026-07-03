<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class FakeHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $handledRequest = null;

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->handledRequest = $request;

        return new Response(200);
    }
}
