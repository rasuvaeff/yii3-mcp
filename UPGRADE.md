# Upgrade guide

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
