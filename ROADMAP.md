# Roadmap

Direction after v1.0, in dependency order. Not a promise of dates; items ship
when they are ready and only if they still make sense. Feedback and real-world
use cases are welcome in [issues](https://github.com/rasuvaeff/yii3-mcp/issues).

## v1.0.1 — developer-experience patch (shipped 2026-07-04)

- `mcp:list` console command — introspect registered tools/resources/prompts
  (with schemas) without an MCP client.
- `Testing\SchemaSnapshot` — contract canary: a committed JSON snapshot of all
  served capability schemas; accidental drift (a changed method signature
  silently changes the generated `inputSchema`) fails the build until the
  snapshot is regenerated deliberately.
- `examples/` extended to cover every mode: stdio, conditional registration,
  Markdown prompts, OpenAPI bridge.

## v1.1.0 — extension point + built-in guards (shipped 2026-07-05)

1. **`ToolCallInterceptorInterface`** — a public extension point wrapping every
   `tools/call` (`intercept(ToolCallContext $context, callable $next)`),
   applied in configured order to all registration paths (attribute tools,
   OpenAPI bridge, configurators). Consumers hang their own tracing,
   rate-limiting or ACL on it without forking.
2. **Session budget** — a per-session `tools/call` counter with a configurable
   limit (MCP error once exhausted). Protection against an agent looping
   inside one session — not a client quota.
3. **Per-session tool visibility** — `Visibility\ToolVisibilityInterface`
   filtering `tools/list` and fail-closed checking `tools/call` per session.
4. **Tenant-scoped MCP** — README recipe for
   [rasuvaeff/yii3-tenancy](https://github.com/rasuvaeff/yii3-tenancy).
   Per-tenant secrets stay a future extension on demand.
5. **Server configurators** — `ServerConfiguratorInterface` as a public
   extension point for companion packages.

## Bridge packages — status

- **[`rasuvaeff/yii3-mcp-audit-log-bridge`](https://github.com/rasuvaeff/yii3-mcp-audit-log-bridge)**
  *(published, v1.0.0)* — `AuditTrailInterceptor` records every `tools/call`
  (client info, tool, masked arguments, result/error, duration) into
  [rasuvaeff/yii3-audit-log](https://github.com/rasuvaeff/yii3-audit-log):
  the enterprise answer to "what did the AI do in our system".
- **[`rasuvaeff/yii3-mcp-rbac-bridge`](https://github.com/rasuvaeff/yii3-mcp-rbac-bridge)**
  *(published, v1.0.0)* — per-user RBAC on tool calls: `RequiredPermission`,
  `RbacToolCallInterceptor`, `RbacToolVisibility`, session-identity binding.
- **[`rasuvaeff/yii3-mcp-telemetry-bridge`](https://github.com/rasuvaeff/yii3-mcp-telemetry-bridge)**
  *(published, v1.0.0)* — a `mcp.tool <name>` span and RED-style metrics for
  every `tools/call` via rasuvaeff/yii3-telemetry + rasuvaeff/yii3-metrics.

## v1.2 — observability + declarative DX

Core items (2–5) shipped in v1.2.0; the telemetry bridge (1) ships as a
separate package once the observability stack
([rasuvaeff/yii3-telemetry](https://github.com/rasuvaeff/yii3-telemetry),
[rasuvaeff/yii3-metrics](https://github.com/rasuvaeff/yii3-metrics)) is
published on Packagist.

1. **`rasuvaeff/yii3-mcp-telemetry-bridge`** (new bridge package, *pending*) —
   the flagship item: makes AI access to the application fully observable
   through the published observability stack.
   - `TracingToolCallInterceptor` over
     [rasuvaeff/yii3-telemetry](https://github.com/rasuvaeff/yii3-telemetry):
     a `mcp.tool <name>` span per `tools/call` — client name/version from the
     handshake, masked arguments, outcome, remaining session budget. In Tempo
     the waterfall reads `POST /mcp` → `mcp.tool order.status` → `db.query`.
   - `MetricsToolCallInterceptor` over
     [rasuvaeff/yii3-metrics](https://github.com/rasuvaeff/yii3-metrics):
     `mcp_tool_calls_total{tool,outcome}` + duration histogram — RED for AI
     traffic.
   - stdio caveat baked in: `mcp:serve` is long-running, so the tracing
     interceptor flushes after each call (MCP call rates make that cheap);
     the HTTP path relies on the backend's shutdown flush.
2. **`Interceptor\ArgumentMasker` in the core** *(shipped in v1.2.0)* — one
   shared sensitive-argument masking helper (`password`/`token`/`secret`/…
   keys at every nesting level). The audit bridge currently masks via
   yii3-audit-log's masker; the telemetry bridge needs identical semantics —
   both consume the core helper instead of drifting apart.
3. **Declarative visibility in params** *(shipped in v1.2.0)* —
   `'visibility' => ['deny' => ['admin.*'], 'allow' => [...]]` (wildcards):
   the typical "hide admin tools from the public client" case without writing
   a `ToolVisibilityInterface` class; the interface stays for complex logic
   (`Visibility\DeclarativeToolVisibility`, mutually exclusive with
   `tool_visibility`).
4. **Document structured output** *(shipped in v1.2.0)* — `outputSchema` /
   `structuredContent` covered by tests, README, llms.txt and an example;
   `Testing\SchemaSnapshot` guards output schemas like input schemas.
5. **`mcp:list --json`** *(shipped in v1.2.0)* — machine-readable capability
   listing for CI diffs and external automation.

## v1.6.0 — hooks for every capability (shipped 2026-07-24)

`prompts/get` and `resources/read` (static + templates) get their own
interceptor chains (`PromptGetInterceptorInterface` /
`ResourceReadInterceptorInterface` with `PromptGetContext` /
`ResourceReadContext`) and per-session visibility
(`PromptVisibilityInterface` / `ResourceVisibilityInterface`) filtering
`prompts/list`, `resources/list`, `resources/templates/list` AND fail-closed
hiding direct calls (hidden = not found). Shared `CallOutcome`
(`success`/`rejected`/`error`) gives audit/telemetry bridges one outcome
vocabulary. Tool-interceptor order and `ToolCallInterceptorInterface`
signature unchanged.

## v1.5.0 — OpenAPI output schema (shipped 2026-07-24)

Bridged tools advertise `outputSchema` in `tools/list` when the operation's
lowest concrete 2xx response carries an `application/json` schema of
`type: object` (local `$ref`s resolved, top-level keywords canonicalized).
Array/scalar responses and `2XX` wildcards stay unadvertised;
`structuredContent` flows for JSON object payloads either way.

## On demand — waiting for a real use case

| Feature | Notes |
|---|---|
| Per-tenant endpoint secrets | the v1.1 tenant recipe keeps one global secret |
| Human-in-the-loop approval for write-tools | prefer MCP task-augmented tools over homegrown pending/poll semantics |
| Outbox mode for write-tools | record the call into [rasuvaeff/yii3-outbox](https://github.com/rasuvaeff/yii3-outbox) instead of executing — durable, retryable, human-reviewable |
| Dry-run interface for tools | needs per-tool support; demand first |
| Multiple named servers (admin vs public) | separate secrets/endpoints; waiting for a real case |

## Deliberately out of scope

- **OAuth 2.1** — until the MCP authorization spec stabilizes
  (shared-secret / network ACL until then).
- **MCP client** (consuming other servers) — a different axis, possibly a
  separate package.
- **Per-session tool registration** — visibility filtering only; no
  per-session state machines in the core without a driving use case.
