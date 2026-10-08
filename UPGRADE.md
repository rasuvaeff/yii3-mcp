# Upgrade guide

## 3.x → 4.0.0

4.0 moves to `mcp/sdk` `~0.8.1` and serves the MCP revision `2026-07-28` —
the stateless era: no `initialize`, no session, every request self-contained
— next to the handshake era on the same endpoint. 3.x is not maintained
separately: fixes land in 4.x. Rationale for
each change: `CHANGELOG.md`; the model: README, "Protocol revisions".

### Required: configuration

| 3.x | 4.0 | If you do nothing |
|---|---|---|
| `'session' => ['budget' => N]` | `'tool_call_budget' => ['calls' => N, 'window' => 3600]` | the build fails, naming the new key |
| — | `'modern_era' => true` (default) | the stateless era is served |
| a budget, no PSR-16 cache in the container | bind `Psr\SimpleCache\CacheInterface`, or `'modern_era' => false` | the build fails: the stateless era has no session to count calls in, so the budget counts per client id in PSR-16 |
| `'protocol_version' => '2026-07-28'` | a handshake revision, or `''` | config load fails; `initialize` now negotiates the client's revision anyway |

With a single `endpoint_secret` every caller is client `"default"`: on the
stateless era they share ONE tool-call budget. Use `client_secrets` for
per-client budgets. `./yii mcp:doctor` reports this (`protocol_eras`).

### Required: code

```php
// interceptors: getClientInfo(): array → clientInfo(): ?Mcp\Schema\Implementation
$name = $context->getClientInfo()['name'] ?? 'unknown';   // 3.x
$name = $context->clientInfo()?->name ?? 'unknown';      // 4.0 — both eras

// the budget interceptor was renamed
new SessionBudgetInterceptor(budget: 50);                 // 3.x
new ToolCallBudgetInterceptor(budget: 50, cache: $cache); // 4.0

// resource updates: argument order, no return value
$sent = $notifier->notify($context, $uri);                // 3.x
$notifier->notify($uri, $context);                        // 4.0; $context optional
```

On the stateless era `getClientInfo()` would have returned `[]` silently,
and `SessionBudgetInterceptor` would have counted from zero on every call —
which is why they are replaced rather than kept.

### Review: anything that keeps state "per session"

On the stateless era the SDK hands every request a **throwaway** session. A
custom interceptor, visibility filter or tool that stores something in
`$session` and reads it on a later call sees nothing there. The package's own
guards are era-aware; check yours, and test them on both eras:
`new McpTester($server, $f, $f, $f, ProtocolVersion::V2026_07_28)`.

Tools that ask the user (`ClientGateway::elicit()`) run **again from the top**
on the stateless era once the answer arrives: move side effects after the
last ask. More than one ask per call needs `request_state.key` (≥ 32 bytes,
from the environment). `sample()`/`listRoots()` throw on `2026-07-28`.

### Behaviour visible to clients and tests

| What | 3.x | 4.0 |
|---|---|---|
| calling a hidden tool | tool error `not available in this session` | JSON-RPC `-32602 Tool not found: "x".`, as for a missing tool |
| missing tool / prompt / completion ref | `-32601` / `-32002` / `-32002` | `-32602` (resources: `-32002` before 2026-07-28, `-32602` from it) |
| list result with `result_json: compact` | sent as `structuredContent` | `structuredContent` only on 2026-07-28 (older revisions require an object) |
| `initialize` | always answered 2025-11-25 | answers the client's revision when supported |
| `McpTester` JSON-RPC error | `RuntimeException` | `Testing\McpErrorException` (still a `RuntimeException`, same message) with `errorCode`/`errorData` |

### Optional: new stateless-era settings

`notifications.bus` (`psr16` under PHP-FPM) to deliver resource updates to
`subscriptions/listen` streams; `cache_policy` for SEP-2549 caching hints;
`header_validation` (on); `request_state`. Interceptors can now ask the user
before a call and read the caller's trace through `$context->requestContext`.

### Bridges

`yii3-mcp-rbac-bridge`, `yii3-mcp-audit-log-bridge` and
`yii3-mcp-telemetry-bridge` releases that allow `rasuvaeff/yii3-mcp` `^4.0`
are required alongside it.

## 2.x → 3.0.0

No manual steps. The only contract change is
`OpenApiServerConfigurator::__construct()` accepting the executor as
`OpenApi\OperationExecutorInterface` instead of the concrete
`HttpOperationExecutor` — every construction that was valid in 2.x passes the
same `HttpOperationExecutor` instance and keeps working; consumers always
obtain the configurator from `OpenApiBridgeFactory` anyway. The major exists
because widening an `@api` constructor parameter is a boundary under SemVer,
declared deliberately in `CHANGELOG.md`.

## 1.x → next major (security-hardening release)

Manual steps you must take before/while upgrading. The full rationale for
each change is in `CHANGELOG.md`.

### `ToolCallLimiterInterface` implementations

The signature changed to typed identity absence:

```php
// before
public function allow(string $clientId, string $toolName): bool
// after
public function allow(?string $clientId, string $toolName): bool
```

`RateLimitInterceptor` no longer has a `$fallbackClientId` constructor
parameter — an identity-less transport (stdio) now passes `null` and your
limiter decides how to bucket it. If you relied on the old `'anonymous'`
fallback key, map `null` to it inside your implementation to keep existing
counters.

### `CachingToolCallInterceptor` constructed manually

The constructor gained a required `namespace` parameter (a stable
application/server identity). Config-plugin users get it automatically
(`cache.namespace` param, defaulting to `server_name`); manual wiring must
pass it:

```php
new CachingToolCallInterceptor($cache, $ttlMap, namespace: 'my-app', identityProvider: $provider);
```

All previously cached tool results miss once after the upgrade — the key
format is versioned and changed deliberately.

### OpenAPI spec behind authentication

`openapi.headers` is no longer sent with the spec fetch. If your
`spec_path` endpoint requires auth, set the new `openapi.spec_headers`
explicitly:

```php
'openapi' => [
    'headers' => ['Authorization' => 'Bearer ' . getenv('MCP_API_TOKEN')],      // operations
    'spec_headers' => ['Authorization' => 'Bearer ' . getenv('MCP_SPEC_TOKEN')], // spec fetch
],
```

A `spec_path` URL embedding credentials (`https://user:pass@…`) is now
rejected — move them into `spec_headers`.

### Sessions

- Live sessions created before the upgrade carry no owner and are rejected
  (404) for authenticated clients — MCP clients recover by re-initializing.
  No action needed beyond expecting one reconnect.
- The default session directory changed from `/tmp/yii3-mcp-sessions` to an
  application-specific `yii3-mcp-sessions-<server_name>-<hash>` created
  `0700`. If you configured `session.dir` explicitly, nothing changes, but
  `mcp:doctor` now FAILS when the directory is readable by group/others —
  `chmod 0700` it (session files are clamped to `0600` by the shipped
  store).
- If you bound `Mcp\Server\Session\FileSessionStore` yourself, switch to
  `Rasuvaeff\Yii3Mcp\Session\PrivateFileSessionStore` (or accept
  world-readable session files knowingly).
- If you construct `McpAction` manually, pass the session store
  (`sessionStore:` parameter) — without it session-to-client ownership is
  NOT enforced.

### Upstream response size

Bridged operations now fail when the upstream body exceeds
`openapi.max_response_bytes` (default 4 MiB). If you legitimately proxy
larger responses, raise the cap explicitly.

### Duplicate capability names

A tool/resource/template/prompt name registered twice — on any combination
of registration paths — now fails the server build with
`DuplicateCapabilityException`. Previously one handler silently won. If your
build starts failing, two of your registrations genuinely collide; rename
one.

### Duplicate client secrets

`StaticSecretResolver` (and therefore `client_secrets`) rejects the same
secret under two client ids at construction. Issue distinct secrets.
