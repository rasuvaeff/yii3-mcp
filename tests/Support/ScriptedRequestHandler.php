<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Answers one JSON-RPC method with a fixed script of results, in order, and
 * remembers the requests it served — a stand-in for a peer that sends what
 * the SDK's own handlers never would (a repeated or non-string cursor).
 * Serving more requests than scripted plus the allowance fails the call, so
 * a client that loops forever on a misbehaving peer is a failed test, not a
 * hung one.
 *
 * @implements RequestHandlerInterface<mixed>
 */
final class ScriptedRequestHandler implements RequestHandlerInterface
{
    /**
     * @var list<Request>
     */
    public array $requests = [];

    /**
     * @param list<array<string, mixed>> $results one result per request, in order
     */
    public function __construct(
        private readonly string $method,
        private array $results,
        private readonly int $maxRequests = 8,
    ) {}

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request::getMethod() === $this->method;
    }

    #[\Override]
    public function handle(Request $request, SessionInterface $session): \Mcp\Schema\JsonRpc\Response
    {
        $this->requests[] = $request;

        if (count($this->requests) > $this->maxRequests) {
            throw new \LogicException('The scripted peer was asked for more than ' . $this->maxRequests . ' pages');
        }

        return new Response($request->getId(), array_shift($this->results) ?? []);
    }
}
