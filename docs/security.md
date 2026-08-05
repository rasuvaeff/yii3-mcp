---
title: "Security"
description: "Securing an MCP server: the fail-closed shared secret, client identity and secret rotation, session ownership, and size-bounded output in yii3-mcp."
---

# Security

**The endpoint is trusted-only.** MCP tools execute application code; treat
the endpoint like an admin API. Ship it behind `SharedSecretMiddleware` or an
explicit network ACL — OAuth from the MCP authorization spec is deliberately
out of scope until it stabilizes.

## Shared secret

`SharedSecretMiddleware` is fail-closed by construction: with **neither**
`endpoint_secret` **nor** `client_secrets` configured, every request is
rejected with an explanatory 503 —

> "MCP endpoint is not configured: the shared secret is empty. Set the
> `endpoint_secret` param (env `MCP_SECRET`), configure `client_secrets`, or
> protect the endpoint with a network ACL instead of this middleware."

— rather than silently serving unprotected. A request with a missing or
wrong secret gets a 401. Comparison is always `hash_equals()`
(constant-time); the resolved client id — never the raw secret — travels
downstream as the `SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE` request
attribute.

## Client identity and secret rotation

One endpoint can serve several MCP clients, each with its own secret, and
each client may hold **several active secrets** during a rotation window
(add the new secret, roll the clients, remove the old one — a removed secret
is revoked immediately):

```php
'rasuvaeff/yii3-mcp' => [
    'client_secrets' => [
        'ci' => getenv('MCP_SECRET_CI'),
        'claude' => [getenv('MCP_SECRET_CLAUDE_OLD'), getenv('MCP_SECRET_CLAUDE_NEW')],
    ],
],
```

`Identity\SecretResolverInterface` resolves the presented header value to a
client id; `client_secrets` and the single-value `endpoint_secret` are
mutually exclusive — the latter is the backward-compatible adapter, one
client named `default`. A secret shared by two different client ids is a
fail-fast configuration error at resolver construction time: resolution
returns the first match, so a duplicate would silently attribute one
client's calls to another. On stdio (`mcp:serve`) there is no HTTP request,
so the resolved client id is always `null`.

The resolved client id is what interceptors see as `ToolCallContext::$clientId`
(see [Interceptors](/interceptors)), what a [visibility](/visibility)
implementation can branch on via the session, and what gets stamped into the
session as its immutable owner — see below. See
[Cookbook: rotating the shared secret](/cookbook/secret-rotation) for the
rotation procedure end to end.

## Session ownership

The SDK itself only checks that a presented `Mcp-Session-Id` exists — nothing
stops one authenticated client from acting inside, or `DELETE`-ing, another
client's session by replaying its id, which can leak into proxy and client
logs via the HTTP header. When a client identity is configured
(`client_secrets` or `endpoint_secret`), `McpAction` closes this:

1. **Stamped at `initialize`, immutably.** The owner is written into the
   session right **after** the `initialize` response — not on the first
   capability call — because first-call binding would let whoever replays a
   fresh session id first claim it.
2. **Verified on every `POST`/`DELETE`**, before the transport runs. A
   request presenting a foreign or ownerless session gets the SDK's own
   404 shape (`"Session not found or has expired."`), indistinguishable
   from an expired session — a foreign session cannot even be probed for
   existence.
3. **Enforced a second time inside the reference handler.**
   `InterceptingReferenceHandler::clientId()` reads identity from the
   session first and throws `Exception\SessionOwnershipException` on an
   owner/holder mismatch. This runs **before** visibility, so a foreign
   session is never consulted for what the caller may see, and it keeps
   identity correct under Fiber-interleaved runtimes where a process-local
   holder alone cannot be trusted.

A session created before ownership stamping existed, or one with no
recorded owner for any other reason, counts as **not owned** — fail-closed,
never silently adoptable. Deployments without client identity
(network-ACL-only) are unaffected by any of this.

## Sessions on disk

The MCP Streamable HTTP session spans several requests, so this package
defaults `SessionStoreInterface` to `Session\PrivateFileSessionStore` —
file-based (FPM-safe, unlike the SDK's in-memory default) and owner-only:
the directory is created `0700` (application-specific, under
`sys_get_temp_dir()`, derived from `server_name`; override via
`session.dir`) and every session file is clamped to `0600`, because session
JSON carries client metadata and everything needed to replay a session id.
For multi-host setups, rebind the interface to a shared PSR-16-backed store.

## Capability name collisions

Capability names (tool, resource, resource-template, prompt) must be unique
across the **whole** server. The SDK's own registry is last-write-wins with
no duplicate check — a collision between any two registration paths
(attribute tools, `ServerConfiguratorInterface`s, the OpenAPI bridge,
Markdown prompts) would silently drop one handler while name-keyed rules
(visibility, cache, RBAC, audit) kept matching a vanished target.
`GuardedRegistry` (wrapping the SDK registry) makes this a build-time
`Exception\DuplicateCapabilityException` instead — see
[Architecture](/intro/architecture) for where it sits in the build.

## Output is size-bounded before allocation, not after

Every caller-influenced output this package produces is bounded **before**
the bytes are materialized, not truncated after the fact:

| Output | Bound |
|---|---|
| Upstream HTTP response bodies (OpenAPI bridge) | `openapi.max_response_bytes`, enforced by an incremental read — a `Content-Length` already over the cap is rejected without reading at all. |
| Substituted Markdown prompts | `limits.prompt_result_bytes`, checked arithmetically before the substituted string is built. |
| OpenAPI spec documents | 10 MiB cap plus an explicit `$ref` depth/node budget during inlining, for URL and file sources alike. |
| Tool results | `limits.tool_result_bytes` — see [Interceptors: result size limit](/interceptors#result-size-limit-and-caching). |

## What this package does not do

- **No tools are registered by default.** Every exposed operation is an
  explicit entry in `tools`, `openapi.operations`, or a
  `ServerConfiguratorInterface`.
- **Tool errors never leak internals.** The SDK returns them as MCP error
  envelopes, not 500 traces.
- **No OAuth.** The MCP authorization spec is out of scope until it
  stabilizes; use the shared secret or a network ACL.
- **Bridged OpenAPI operations execute with the configured upstream
  credentials, not the MCP caller's identity**, unless delegated
  authorization (`openapi.identity_provider` +
  `openapi.delegated_header_provider`) is explicitly configured — see
  [OpenAPI bridge](/openapi-bridge).
