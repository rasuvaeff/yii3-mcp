---
title: "Protocol"
---

# Protocol

## Transports

| Transport | Entry point | Use case |
|---|---|---|
| Streamable HTTP | `McpAction` (PSR-15) | Production: an MCP client connects over the network with a shared-secret header. |
| stdio | `McpServeCommand` (`mcp:serve`) | Local development: the client spawns the command as a subprocess and speaks JSON-RPC over stdin/stdout. |

Both are served from the same `Mcp\Server` instance built by
`McpServerFactory` — a tool class registers identically for either
transport.

### Streamable HTTP

`McpAction::handle()` runs the SDK's `StreamableHttpTransport` against the
current PSR-7 request. Requests are `POST`/`GET`/`DELETE`/`OPTIONS`; a session
is created on `initialize` and identified afterward by the `Mcp-Session-Id`
response header, which the client echoes back on every subsequent call.
Behind that:

- **CORS**, **DNS-rebinding protection**, and **protocol-version** middleware
  wrap every call when `allowed_hosts` is configured — the SDK's default host
  allow-list (`localhost`, `127.0.0.1`, `[::1]`) always applies; production
  deployments behind a real domain add it to `allowed_hosts`.
- The response may switch to **Server-Sent Events** framing mid-call whenever
  the handler suspends to send a progress/log notification or a sampling/
  elicitation round trip — clients must handle both plain-JSON and SSE-framed
  bodies.

### stdio

`McpServeCommand` extends `Symfony\Component\Console\Command\Command`
directly and runs the SDK's stdio transport over the process's own stdin/
stdout. There is no HTTP request, so `SharedSecretMiddleware` never runs and
the resolved client id is always `null` for interceptors and caching — see
[Security](/security#client-identity-and-secret-rotation).

## Sessions

An MCP Streamable HTTP session spans several HTTP requests: `initialize`
first, then `tools/call` (and friends) carrying the returned
`Mcp-Session-Id`. The SDK's default in-memory session store would lose the
session between PHP-FPM workers, so this package binds
`Session\PrivateFileSessionStore` by default — file-based, owner-only
(`0700` directory, `0600` files), under an application-specific directory
derived from `server_name`. For multi-host deployments, rebind
`SessionStoreInterface` to a shared PSR-16-backed store
(`Mcp\Server\Session\Psr16SessionStore`).

Sessions are also **bound to the client that created them** when a client
identity is configured — see [Security: session ownership](/security#session-ownership)
for the exact enforcement.

## Capabilities

The SDK negotiates capabilities during `initialize` based on what the built
`Server` actually has: tools, resources (with `resources.subscribe`
whenever any resource exists), prompts, and completion. See
[Capabilities](/capabilities) for how each is declared and served.

## Protocol version

The served MCP revision comes from the SDK (`2025-11-25` under the current
`~0.7.0` pin) and is **not negotiated** — `initialize` answers with this
revision regardless of what the client requested. Pin a specific revision
with `protocol_version` in params; an unsupported value fails at config-load
time, not on the first request. Do not hardcode a revision anywhere in
application code that talks to this server — read it from the SDK's own
`ProtocolVersion` enum, exactly like `Testing\McpTester` does, so a test
client and the server can never silently disagree.
