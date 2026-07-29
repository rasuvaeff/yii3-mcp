<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final readonly class StreamBodyHttpClient implements ClientInterface
{
    public function __construct(
        private StreamInterface $body,
        private int $statusCode = 200,
    ) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response($this->statusCode, [], $this->body);
    }
}
