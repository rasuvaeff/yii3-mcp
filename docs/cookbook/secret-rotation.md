---
title: "Cookbook: rotating the shared secret"
---

# Cookbook: rotating the shared secret

`client_secrets` supports **several active secrets per client id** — that's
what makes a rotation safe: the old secret keeps working while every client
is rolled onto the new one, instead of a hard cutover that breaks every
in-flight agent at once. See [Security: client identity and secret
rotation](/security#client-identity-and-secret-rotation) for the underlying
model.

## Before: single client, one secret

```php
'rasuvaeff/yii3-mcp' => [
    'client_secrets' => [
        'claude' => getenv('MCP_SECRET_CLAUDE'),
    ],
],
```

## 1. Generate the new secret, add it alongside the old one

```php
'rasuvaeff/yii3-mcp' => [
    'client_secrets' => [
        'claude' => [
            getenv('MCP_SECRET_CLAUDE_OLD'),  // still accepted
            getenv('MCP_SECRET_CLAUDE_NEW'),  // now also accepted
        ],
    ],
],
```

Deploy this first. Both secrets resolve to the same client id (`claude`),
so nothing about interceptors, caching, or session ownership changes during
the window — they all key off the client id, never the secret itself.

## 2. Roll every client onto the new secret

Update whatever holds `MCP_SECRET_CLAUDE` in each client's configuration —
Claude Code/Desktop config, CI variables, any other holder — to the new
value. There is no server-side signal for "all clients rolled"; track it
operationally (a deploy checklist, a dashboard of which secret value is
producing traffic if your [telemetry bridge](/bridges/telemetry) is wired
up with per-client attributes).

## 3. Remove the old secret

```php
'rasuvaeff/yii3-mcp' => [
    'client_secrets' => [
        'claude' => getenv('MCP_SECRET_CLAUDE_NEW'),  // back to a single value
    ],
],
```

**The old secret is revoked immediately** on this deploy — there is no
additional grace period once it's removed from the list. Any client still
presenting it gets a 401.

## Verify with mcp:doctor

```bash
./yii mcp:doctor
```

`mcp:doctor`'s config checks report the configured `client_secrets` ids
(never the secret values themselves) — confirm the expected client ids are
present before and after each step. See
[Operations](/operations#diagnostics-mcp-doctor).

## Common mistakes

- **Reusing a secret value across two client ids.** `SharedSecretMiddleware`
  resolves the *first* matching client id — a duplicate would silently
  attribute one client's calls to another. This is a fail-fast
  configuration error at resolver construction, not a runtime ambiguity, so
  it will surface immediately on deploy, not as a slow leak.
- **Rotating `endpoint_secret` instead of migrating to `client_secrets`
  first.** The single-value `endpoint_secret` form has no rotation window —
  changing it is a hard cutover for every client at once (they all resolve
  to the one `default` client id). Migrate to `client_secrets` (even with
  one entry) before your first rotation if you need a grace window.
- **Forgetting the secret never travels past the middleware.** Only the
  resolved client id reaches interceptors, caching keys, and the audit
  trail — there is nothing to update in application code when a secret
  value changes, only in `client_secrets` and the clients themselves.
