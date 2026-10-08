<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Closure;
use InvalidArgumentException;
use Mcp\Exception\ToolCallException;
use Mcp\Server\Session\SessionInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Caps the number of tools/call an agent can make in a row — protection
 * against an agent burning calls in a loop, not a client quota (that belongs
 * to an application-level rate limiter, see {@see RateLimitInterceptor}).
 *
 * What "in a row" means depends on the protocol era:
 *
 * - Handshake era: per MCP session (initialize → TTL expiry), counted in the
 *   session itself. Re-initializing starts a fresh counter.
 * - Modern era (2026-07-28): there is no session — the SDK hands every request
 *   a throwaway one, so a session counter would start at zero on every call
 *   and never run out. The budget is counted per client id instead, in PSR-16,
 *   over a fixed window (`floor(now / window)` is part of the key, so a client
 *   that keeps calling is still reset when the window turns — a TTL re-armed
 *   on every write would never expire). Without a client id (no
 *   SharedSecretMiddleware) every anonymous caller shares ONE budget: the
 *   stricter direction, on purpose.
 *
 * Fail-closed: a modern-era call with no cache configured, or a cache backend
 * that throws, is rejected — a guard must not turn into "unlimited" on an
 * outage. An exhausted budget is reported as a regular MCP tool-error
 * envelope, so the agent sees the reason instead of a transport failure. A
 * failed call still consumes the budget.
 *
 * NOT safe against concurrent calls: both counters are a plain
 * read-modify-write (`get()` then `set()`) with no compare-and-swap, because
 * neither the SDK's {@see SessionInterface} nor PSR-16 exposes one. N
 * concurrent requests can overrun the budget by up to N-1 calls. Accepted: the
 * guard stops a runaway agent loop; a hard cap under adversarial concurrency
 * belongs in a rate limiter with an atomic store.
 *
 * @api
 */
final readonly class ToolCallBudgetInterceptor implements ToolCallInterceptorInterface
{
    private const string SESSION_COUNTER_KEY = 'rasuvaeff.yii3-mcp.tool-calls';

    /**
     * Distinct from {@see CachingToolCallInterceptor}'s prefix, so budget
     * counters and cached results never collide on a shared backend.
     */
    private const string KEY_PREFIX = 'yii3-mcp.budget.';

    /** PSR-16 guarantees keys up to 64 characters: prefix (16) + 45 */
    private const int KEY_HASH_LENGTH = 45;

    private const int KEY_FORMAT_VERSION = 1;

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param int $budget tool calls allowed per session (handshake) or per client and window (modern)
     * @param CacheInterface|null $cache backs the modern-era counter; null rejects modern-era calls
     * @param int $window modern-era window in seconds
     * @param string $namespace isolates this server's counters on a shared cache backend
     * @param (Closure(): int)|null $clock unix time source; null = time()
     */
    public function __construct(
        private int $budget,
        private ?CacheInterface $cache = null,
        private int $window = 3600,
        private string $namespace = 'yii3-mcp',
        ?Closure $clock = null,
    ) {
        if ($budget < 1) {
            throw new InvalidArgumentException(sprintf('Tool-call budget must be at least 1, %d given', $budget));
        }

        if ($window < 1) {
            throw new InvalidArgumentException(sprintf('Tool-call budget window must be at least 1 second, %d given', $window));
        }

        $this->clock = $clock ?? time(...);
    }

    #[\Override]
    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        $session = $context->session;

        if (RequestEra::isModern($session)) {
            $this->consumeClientBudget($context->clientId);

            return $next();
        }

        if (!$session instanceof SessionInterface) {
            return $next();
        }

        /** @var mixed $used */
        $used = $session->get(self::SESSION_COUNTER_KEY, 0);
        $used = is_int($used) ? $used : 0;

        if ($used >= $this->budget) {
            throw new ToolCallException(sprintf('Session tool-call budget of %d is exhausted; start a new session or raise the budget', $this->budget));
        }

        $session->set(self::SESSION_COUNTER_KEY, $used + 1);

        return $next();
    }

    private function consumeClientBudget(?string $clientId): void
    {
        if (!$this->cache instanceof CacheInterface) {
            throw new ToolCallException('Tool-call budget is unavailable: no cache is configured for the stateless protocol era');
        }

        $now = ($this->clock)();
        $bucket = intdiv($now, $this->window);
        // typed key material: a null client id is distinct from every string
        $key = self::KEY_PREFIX . substr(hash('sha256', json_encode([
            'v' => self::KEY_FORMAT_VERSION,
            'namespace' => $this->namespace,
            'client' => $clientId,
            'window' => $this->window,
            'bucket' => $bucket,
        ], JSON_THROW_ON_ERROR)), 0, self::KEY_HASH_LENGTH);

        try {
            /** @var mixed $used */
            $used = $this->cache->get($key, 0);
        } catch (\Throwable $e) {
            throw new ToolCallException('Tool-call budget is unavailable', (int) $e->getCode(), previous: $e);
        }

        $used = is_int($used) ? $used : 0;

        if ($used >= $this->budget) {
            throw new ToolCallException(sprintf(
                'Tool-call budget of %d per %d seconds is exhausted; retry in %d seconds',
                $this->budget,
                $this->window,
                ($bucket + 1) * $this->window - $now,
            ));
        }

        try {
            // TTL to the end of THIS window — never re-armed past it
            $this->cache->set($key, $used + 1, ($bucket + 1) * $this->window - $now);
        } catch (\Throwable $e) {
            throw new ToolCallException('Tool-call budget is unavailable', (int) $e->getCode(), previous: $e);
        }
    }
}
