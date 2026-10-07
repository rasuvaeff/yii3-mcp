<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * In-process handler double: records every nested request it served and
 * answers a fixed JSON response.
 */
final class RecordingRequestHandler implements RequestHandlerInterface
{
    /** @var list<ServerRequestInterface> */
    public array $requests = [];

    public function __construct(
        private readonly int $statusCode = 200,
        private readonly string $body = '{"ok":true}',
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): Response
    {
        $this->requests[] = $request;

        return new Response($this->statusCode, ['Content-Type' => 'application/json'], $this->body);
    }
}
