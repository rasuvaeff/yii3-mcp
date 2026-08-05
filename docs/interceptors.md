---
title: "Interceptors"
---

# Interceptors

`Interceptor\ToolCallInterceptorInterface` is the package's public extension
point around tool execution. The chain wraps **every** registration path —
attribute tools, OpenAPI-bridged operations, configurator-registered
handlers — so tracing, rate limiting, ACL, or caching live in one place,
without touching the tools themselves:

```php
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;

final readonly class TracingInterceptor implements ToolCallInterceptorInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        // $context->toolName, $context->arguments, $context->session,
        // $context->clientId, $context->getClientInfo()
        $this->logger->info('tools/call', ['tool' => $context->toolName]);

        return $next();   // skip $next() to short-circuit
    }
}
```

```php
// config/params.php — resolved through the container, first = outermost
'rasuvaeff/yii3-mcp' => [
    'interceptors' => [TracingInterceptor::class],
],
```

Throwing `Mcp\Exception\ToolCallException` from an interceptor rejects the
call with a regular MCP tool-error envelope (the agent sees the reason); any
other exception becomes an opaque internal error.

The same seam exists for the other two capability calls, with their own
interfaces so a tool policy never accidentally applies to a prompt:
`Interceptor\PromptGetInterceptorInterface` (`prompt_interceptors`, sees
`PromptGetContext`) and `Interceptor\ResourceReadInterceptorInterface`
(`resource_interceptors`, sees `ResourceReadContext` — carries the URI and,
for a template match, the extracted RFC 6570 variables). Rejecting from
either throws `Mcp\Exception\PromptGetException` /
`Mcp\Exception\ResourceReadException`; `completion/complete` is **not**
wrapped by any of these chains — see
[Visibility: completions](/visibility#completions-obey-the-same-filters).

## The chain order is fixed and load-bearing

```
tools/call
  └─ SessionBudgetInterceptor        (outermost, only if session.budget > 0)
       └─ configured `interceptors`  (in list order — first = outermost)
            └─ CachingToolCallInterceptor   (only if cache.tools is non-empty)
                 └─ ResponseSizeLimitInterceptor  (innermost, only if limits.tool_result_bytes > 0)
                      └─ the actual tool
```

This order is never configurable and must never be reordered: **caching
sits between your interceptors and the size limit, never around your
interceptors.** If caching wrapped around RBAC/audit, a cache hit would skip
them entirely — turning the cache into an ACL bypass. Configured
interceptors (RBAC, audit, tracing — including the three bridges) always
run, on every call, cache hit or miss. The size limit only runs on a cache
miss: the value it already limited is what gets cached, so a hit never
needs re-limiting.

## Session budget: stop agent loops

```php
'rasuvaeff/yii3-mcp' => [
    'session' => ['budget' => 50],   // 0 = unlimited (default)
],
```

A hard cap on `tools/call` per MCP session (from `initialize` until the TTL
expires). An agent stuck in a loop burns the budget and gets an explanatory
tool error instead of hammering the application. This is loop protection
**inside one session**, not a client quota — a re-`initialize` starts a
fresh counter; client quotas belong in
[an application-level rate limiter](#per-client-rate-limits-bring-your-own-limiter).

The counter is a plain session `get()`/`set()` — deliberately **not**
concurrency-safe, because the SDK's generic `SessionInterface` exposes no
compare-and-swap that would work across every possible
`SessionStoreInterface` backend. N concurrent requests on the same session
can overrun the budget by up to N-1 calls. This is accepted: the guard stops
a runaway agent, it is not a hard cap under adversarial concurrency.

## Masking sensitive arguments

Anything an interceptor sends out of the process — a log line, a trace
span, an audit record — must not carry secrets. `Interceptor\ArgumentMasker`
replaces the values of sensitive keys with `***`, case-insensitively, at
**every** nesting level:

```php
use Rasuvaeff\Yii3Mcp\Interceptor\ArgumentMasker;

$masker = new ArgumentMasker();                       // or: new ArgumentMasker(['ssn', 'password'])
$safe = $masker->mask($context->arguments);
// ['user' => ['name' => 'alice', 'password' => '***']]
```

The default key list: `password`/`pass`/`pwd`, `secret`, `token`/`bearer`/
`jwt`, `auth`/`authorization`, `cookie`, `api_key`/`apikey`/`api-key`/
`x-api-key`, `access_token`/`refresh_token`/`id_token`/`session_token`/
`auth_token` (and their kebab spellings), `client_secret`, `private_key`,
`credit_card`. Comparison is exact after lowercasing — `apikey` matches
`ApiKey` but not `apiKeyHeader`; pass your own list to cover
application-specific names. It is one shared helper so every consumer
(the [audit bridge](/bridges/audit-log), the
[telemetry bridge](/bridges/telemetry), your own interceptors) masks with
identical semantics instead of drifting apart.

## Result size limit and caching

A tool result has no natural upper bound — a bridged GET against a real API,
or a hand-written tool over a large table, can return megabytes of JSON and
burn an agent's context window.

```php
'rasuvaeff/yii3-mcp' => [
    'limits' => ['tool_result_bytes' => 0],   // 0 = unlimited (default)
],
```

An over-limit **string** result is truncated with an explicit marker
reporting the bytes actually kept; any other result (array, object) is
**rejected outright** instead, because truncated JSON is invalid JSON, not a
smaller valid one. The budget counts the raw string's bytes for a string
result (before JSON encoding — control characters expand on the wire, up to
~6x) and the JSON-encoded bytes for arrays/objects. A multi-byte character
that does not fit whole is dropped rather than split, because a broken
UTF-8 sequence would make the JSON-RPC response unencodable and the
Streamable HTTP transport drops such a response **silently**.

For read-heavy tools called repeatedly with the same arguments inside a
session (a lookup table, an OpenAPI GET), `cache.tools` skips the handler
entirely on a hit — opt in by tool name (for the OpenAPI bridge, the
**served** name, after any `tool_names` rename) with a TTL in seconds:

```php
'rasuvaeff/yii3-mcp' => [
    'cache' => ['tools' => ['blog_tags_list' => 60]],
],
```

The cache key always includes the resolved client id — a shared cache
between distinct clients must never leak one client's result to another.
The key material is a typed JSON structure carrying a format version: an
**absent** client id (stdio) encodes as `null`, never as a sentinel string a
real client id (`anonymous`) could collide with. The mandatory `namespace`
(`cache.namespace`, defaulting to `server_name`) isolates applications
sharing one cache backend. When `openapi.identity_provider` is configured,
the resolved `ExecutionIdentity` is part of the key too — delegated upstream
credentials can make a result identity-specific below the client-id level
(many end users behind one MCP client). Only successful results are cached;
an exception never is. A cache read/write failure fails **open** — an
availability optimization, not a security gate. An identity **provider**
failure fails **closed** for cached tools: serving a result without knowing
whose it is would be exactly the leak the key exists to prevent.

The key identifies the **MCP client**, not the application user behind it —
a tool whose result depends on who is logged in must not be cached unless
`openapi.identity_provider` resolves that user. "Idempotent read" is not the
same as "same answer for everyone."

## Per-client rate limits (bring your own limiter)

This package deliberately ships no limiter storage. Implement
`Interceptor\ToolCallLimiterInterface` over the rate limiter your
application already runs, and wire `Interceptor\RateLimitInterceptor` into
`interceptors`:

```php
final readonly class AppToolCallLimiter implements ToolCallLimiterInterface
{
    public function __construct(private CounterInterface $counter) {}

    public function allow(?string $clientId, string $toolName): bool
    {
        return $this->counter->hit(($clientId ?? 'no-client') . ':' . $toolName)->isAllowed();
    }
}
```

The interceptor keys by resolved client id plus tool name. A transport
without identity (stdio) passes `null` — typed absence, not a reserved
string a real client id could collide with. **Fail-closed**: a limiter
backend throw rejects the call — an enforced quota must not silently become
"unlimited" during an outage.

## Retrying transient failures (bring your own retry)

This package ships no retry logic — a naive blanket retry duplicates side
effects on a non-idempotent tool. Scope any retry to an explicit allow-list
of verified-idempotent tools and to transient failure types only, e.g. with
[`rasuvaeff/retry`](https://github.com/rasuvaeff/retry):

```php
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\OperationFailedException;

final readonly class RetryInterceptor implements ToolCallInterceptorInterface
{
    /** @param list<string> $idempotentTools verified idempotent — never blanket-retry */
    public function __construct(private array $idempotentTools) {}

    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        if (!in_array($context->toolName, $this->idempotentTools, true)) {
            return $next();
        }

        return Retry::new()
            ->maxAttempts(3)
            ->withExponential(baseMs: 100, multiplier: 2.0, capMs: 2_000)
            ->retryOn(OperationFailedException::class)   // transient failures only
            ->run($next);
    }
}
```

Place it near the end of `interceptors` (closest to the tool call) — an
interceptor listed *before* it (e.g. `RateLimitInterceptor`) wraps the whole
retry loop and is checked once per outer call, not once per attempt; listed
*after*, it would re-trigger on every retry.

## Shared outcome vocabulary

For consumers that classify calls (the audit and telemetry bridges),
`Interceptor\CallOutcome` (`success` / `rejected` / `error`, with
`CallOutcome::fromThrowable()`) is the one shared vocabulary — a rate-limit
or ACL rejection is classified `rejected` and never pollutes error-rate
metrics.
