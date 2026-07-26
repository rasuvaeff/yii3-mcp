<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Mcp\Server;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Mcp\Identity\ClientIdentityContext;
use Rasuvaeff\Yii3Mcp\Interceptor\InterceptingReferenceHandler;
use Symfony\Component\Uid\Uuid;

/**
 * PSR-15 endpoint serving the MCP Streamable HTTP transport. Route it behind
 * SharedSecretMiddleware (or a network ACL) — the endpoint is trusted-only.
 *
 * When both a resolved client id (SharedSecretMiddleware upstream) and a
 * `$sessionStore` are present, sessions are BOUND to the client that created
 * them: the owner is stamped into the session at `initialize`, immutably, and
 * every subsequent POST/DELETE carrying an `Mcp-Session-Id` is verified
 * against it BEFORE the transport runs. A request presenting another client's
 * session id — or a session with no recorded owner — is answered with the
 * same 404 the SDK uses for a missing session, indistinguishable from an
 * expired one. The SDK itself only checks that the session UUID exists, so
 * without this guard any authenticated client could act inside (or DELETE)
 * another client's session by replaying its id.
 *
 * The SDK's DNS-rebinding protection allows only localhost by default;
 * production deployments behind a real domain list it in $allowedHosts.
 *
 * @api
 */
final readonly class McpAction implements RequestHandlerInterface
{
    private const array LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * @param list<string> $allowedHosts extra hosts accepted by the transport
     *                                   (the SDK's local defaults stay allowed)
     * @param SessionStoreInterface|null $sessionStore the store backing the
     *                                                 server's sessions; required for session-to-client
     *                                                 ownership enforcement (without it, sessions are only
     *                                                 as private as their UUIDs)
     */
    public function __construct(
        private Server $server,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private array $allowedHosts = [],
        private ?SessionStoreInterface $sessionStore = null,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // upstream middleware (body parsers etc.) may have consumed the
        // stream already; the transport reads it again
        $body = $request->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        /** @var mixed $clientId */
        $clientId = $request->getAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE);
        $clientId = is_string($clientId) ? $clientId : null;

        $presentedSessionId = $this->sessionIdFromHeader($request->getHeaderLine(StreamableHttpTransport::SESSION_HEADER));

        if ($clientId !== null
            && $this->sessionStore instanceof SessionStoreInterface
            && $presentedSessionId instanceof Uuid
            && in_array($request->getMethod(), ['POST', 'DELETE'], true)
            && !$this->ownedBy($this->sessionStore, $presentedSessionId, $clientId)
        ) {
            return $this->sessionNotFound();
        }

        // The SDK hands its reference handler the JSON-RPC request, not this
        // PSR-7 one, so the client id resolved by SharedSecretMiddleware is
        // carried through a process-local holder for the duration of the run.
        // After the owner is stamped at initialize, capability calls read the
        // identity from the session itself; the holder covers the remaining
        // sessionless/unstamped paths.
        ClientIdentityContext::arm($clientId);

        try {
            $response = $this->server->run(new StreamableHttpTransport(
                request: $request,
                responseFactory: $this->responseFactory,
                streamFactory: $this->streamFactory,
                middleware: $this->allowedHosts === [] ? null : $this->transportMiddleware(),
            ));
        } finally {
            ClientIdentityContext::disarm();
        }

        // initialize created a new session (no session id on the request, one
        // on the response): stamp the immutable owner NOW, not on first
        // capability call — first-call binding would let whoever replays the
        // fresh session id first claim the session
        if ($clientId !== null
            && $this->sessionStore instanceof SessionStoreInterface
            && $presentedSessionId === null
            && ($created = $this->sessionIdFromHeader($response->getHeaderLine(StreamableHttpTransport::SESSION_HEADER))) instanceof Uuid
        ) {
            $this->stampOwner($this->sessionStore, $created, $clientId);
        }

        return $response;
    }

    /**
     * The `Mcp-Session-Id` value as a UUID, or null when absent/malformed —
     * a malformed value is left for the transport's own 400.
     */
    private function sessionIdFromHeader(string $header): ?Uuid
    {
        try {
            return Uuid::fromString($header);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Fail-closed ownership test: an unreadable/undecodable session or one
     * with no recorded owner counts as NOT owned — a session created before
     * ownership stamping existed cannot be adopted by whoever presents its id
     * first. A session missing from the store passes the guard so the SDK
     * answers with its own regular 404.
     */
    private function ownedBy(SessionStoreInterface $store, Uuid $sessionId, string $clientId): bool
    {
        $raw = $store->read($sessionId);

        if (!is_string($raw)) {
            return true;
        }

        try {
            /** @var mixed $data */
            $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!is_array($data)) {
            return false;
        }

        /** @var mixed $owner */
        $owner = $this->nestedGet($data, InterceptingReferenceHandler::CLIENT_ID_SESSION_KEY);

        return $owner === $clientId;
    }

    private function stampOwner(SessionStoreInterface $store, Uuid $sessionId, string $clientId): void
    {
        $raw = $store->read($sessionId);

        if (!is_string($raw)) {
            return;
        }

        try {
            /** @var mixed $data */
            $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (!is_array($data)) {
            return;
        }

        /** @var array<string, mixed> $data */
        if ($this->nestedGet($data, InterceptingReferenceHandler::CLIENT_ID_SESSION_KEY) !== null) {
            return;
        }

        $data = $this->nestedSet($data, InterceptingReferenceHandler::CLIENT_ID_SESSION_KEY, $clientId);
        $store->write($sessionId, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Mirrors the SDK Session's dot-segmented key semantics on raw decoded
     * session data.
     *
     * @param array<array-key, mixed> $data
     */
    private function nestedGet(array $data, string $key): mixed
    {
        /** @var mixed $node */
        $node = $data;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }

            /** @var mixed $node */
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function nestedSet(array $data, string $key, string $value): array
    {
        $segments = explode('.', $key);
        $segment = array_shift($segments);

        if ($segments === []) {
            $data[$segment] = $value;
        } else {
            /** @var mixed $child */
            $child = $data[$segment] ?? null;
            $data[$segment] = $this->nestedSet(is_array($child) ? $child : [], implode('.', $segments), $value);
        }

        return $data;
    }

    /**
     * The SDK's own "Session not found or has expired." shape — a foreign
     * session must be indistinguishable from a missing one.
     */
    private function sessionNotFound(): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(404)
            ->withHeader('Content-Type', 'application/json');

        return $response->withBody($this->streamFactory->createStream(json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32600, 'message' => 'Session not found or has expired.'],
        ], JSON_THROW_ON_ERROR)));
    }

    /**
     * The SDK default stack with the host allow-list widened — same
     * protections (CORS, DNS rebinding, protocol version), never fewer.
     *
     * @return list<\Psr\Http\Server\MiddlewareInterface>
     */
    private function transportMiddleware(): array
    {
        return [
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware(
                allowedHosts: [...self::LOCAL_HOSTS, ...$this->allowedHosts],
                responseFactory: $this->responseFactory,
                streamFactory: $this->streamFactory,
            ),
            new ProtocolVersionMiddleware(),
        ];
    }
}
