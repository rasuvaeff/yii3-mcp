# Roadmap

Direction after v1.0, in dependency order. Not a promise of dates; items ship
when they are ready and only if they still make sense. Feedback and real-world
use cases are welcome in [issues](https://github.com/rasuvaeff/yii3-mcp/issues).

## v1.0.1 — developer-experience patch (shipped)

- `mcp:list` console command — introspect registered tools/resources/prompts
  (with schemas) without an MCP client.
- `Testing\SchemaSnapshot` — contract canary: a committed JSON snapshot of all
  served capability schemas; accidental drift (a changed method signature
  silently changes the generated `inputSchema`) fails the build until the
  snapshot is regenerated deliberately.
- `examples/` extended to cover every mode: stdio, conditional registration,
  Markdown prompts, OpenAPI bridge.

## v1.1 — planned

1. **`ToolCallInterceptorInterface`** — a public extension point wrapping every
   `tools/call` (`intercept(OperationContext $context, callable $next)`),
   applied in configured order to all registration paths (attribute tools,
   OpenAPI bridge, configurators). Consumers hang their own tracing,
   rate-limiting or ACL on it without forking; the features below are built-in
   consumers of the same mechanism.
2. **AI audit trail** — an interceptor recording every `tools/call` (client
   info, tool, masked arguments, result/error, duration) into
   [rasuvaeff/yii3-audit-log](https://github.com/rasuvaeff/yii3-audit-log):
   the enterprise answer to "what did the AI do in our system".
3. **Session budget** — a per-session `tools/call` counter with a configurable
   limit (MCP error once exhausted). Protection against an agent looping
   inside one session — not a client quota (a new session resets it); client
   quotas stay an application-level rate-limit concern.
4. **Tenant-scoped MCP** — recipe (and a session-store decorator) for
   [rasuvaeff/yii3-tenancy](https://github.com/rasuvaeff/yii3-tenancy):
   tenant resolved before `McpAction`, tools see `CurrentTenant`, sessions
   isolated per tenant.
5. **Per-session tool visibility** — `ToolVisibilityInterface` filtering
   `tools/list` and fail-closed checking `tools/call` per session
   (`ConditionalToolInterface` is build-time and global; this is the
   per-session complement).

## On demand — waiting for a real use case

| Feature | Notes |
|---|---|
| Human-in-the-loop approval for write-tools | prefer MCP task-augmented tools over homegrown pending/poll semantics |
| Outbox mode for write-tools | record the call into [rasuvaeff/yii3-outbox](https://github.com/rasuvaeff/yii3-outbox) instead of executing — durable, retryable, human-reviewable |
| Dry-run interface for tools | needs per-tool support; demand first |
| Per-tool rate limits | mostly covered by session budget + interceptor |
| Multiple named servers (admin vs public) | separate secrets/endpoints; waiting for a real case |

## Deliberately out of scope

- **OAuth 2.1** — until the MCP authorization spec stabilizes
  (shared-secret / network ACL until then).
- **MCP client** (consuming other servers) — a different axis, possibly a
  separate package.
- **Per-session tool registration** — visibility filtering only; no
  per-session state machines in the core without a driving use case.
