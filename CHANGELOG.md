# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.7.0 — 2026-07-25

- Ship an AI agent skill (`resources/skills/rasuvaeff-yii3-mcp/SKILL.md` +
  `extra.skills` in composer.json): projects using the `llm/skills` Composer
  plugin get the skill synced into `.agents/skills/` automatically on install.

## 1.6.0 — 2026-07-24

- Hooks for every capability: `prompts/get` and `resources/read` (static
  resources and templates alike) get their own interceptor chains —
  `Interceptor\PromptGetInterceptorInterface` (`PromptGetContext`) and
  `Interceptor\ResourceReadInterceptorInterface` (`ResourceReadContext`,
  with the RFC 6570 template variables and the matched `uriTemplate`) —
  configured via the `prompt_interceptors` / `resource_interceptors` params
  or `McpServerFactory::create()`. Contexts carry the same session/client
  identity as `ToolCallContext`.
- Per-session prompt/resource visibility:
  `Visibility\PromptVisibilityInterface` and
  `Visibility\ResourceVisibilityInterface` (params `prompt_visibility` /
  `resource_visibility`) filter `prompts/list`, `resources/list` and
  `resources/templates/list`, and fail-closed hide direct `prompts/get` /
  `resources/read` — a hidden capability is reported as not found,
  indistinguishable from a missing one.
- `Interceptor\CallOutcome` — shared `success`/`rejected`/`error` outcome
  vocabulary for audit/telemetry bridges;
  `ToolCallException`/`PromptGetException`/`ResourceReadException` classify
  as `rejected` via `CallOutcome::fromThrowable()`.
- Existing tool-interceptor order and the `ToolCallInterceptorInterface`
  signature are unchanged.

## 1.5.0 — 2026-07-24

- OpenAPI bridge: bridged tools advertise `outputSchema` in `tools/list`
  when the operation's lowest concrete 2xx response carries an
  `application/json` schema of `type: object` — local `$ref`s resolved,
  top-level keywords canonicalized to
  `type`/`properties`/`required`/`additionalProperties`/`description`.
  Array/scalar responses and `2XX` wildcards are not advertised;
  `structuredContent` still flows for JSON object payloads either way.

## 1.4.0 — 2026-07-18

- `Testing\SchemaSnapshot::verify()` — strict CI mode: a missing snapshot
  file is an error, so a deleted or never-committed snapshot cannot yield a
  green build. `record()` deliberately (re)writes the file; the
  `MCP_SNAPSHOT_RECORD=1` environment flag switches `assert()`/`verify()`
  into record mode (the regeneration path — no more "delete the file").
  `assert()` keeps its auto-create-on-first-run behaviour.
- `mcp:doctor` (`McpDoctorCommand` + `Doctor\McpDoctor`) — configuration
  health check: endpoint secret, session directory and store round-trip,
  OpenAPI spec, server build. Stable exit codes (0 healthy, 2 config,
  3 storage, 4 upstream); `--json` machine-readable report; `--probe` opts
  into network access (URL spec fetch) — without it the check is fully
  local. Output never contains the secret or configured header values.
- New runtime dependency: `symfony/uid` (session-store probe).
- Client identity and secret rotation: the new `client_secrets` param maps
  client ids to one or SEVERAL active secrets (rotation window; a removed
  secret is revoked immediately), resolved through the new
  `Identity\SecretResolverInterface` / `Identity\StaticSecretResolver`
  (constant-time comparison). `SharedSecretMiddleware` attributes the
  resolved client id to the request; interceptors see it as
  `ToolCallContext::$clientId` and it is mirrored into the session for
  audit/telemetry bridges — the raw secret never travels past the
  middleware. The single `endpoint_secret` form keeps working as the client
  `default`; configuring both forms is a fail-fast error (also reported by
  `mcp:doctor`).
- Per-client/per-tool rate limits by delegation: implement the new
  `Interceptor\ToolCallLimiterInterface` over the application's rate limiter
  and wire the new `Interceptor\RateLimitInterceptor` into `interceptors`.
  Fail-closed: a limiter outage rejects the call instead of silently lifting
  the quota. The package deliberately ships no limiter storage.

## 1.3.0 — 2026-07-18

- `Testing\McpTester`, `Testing\SchemaSnapshot` and `mcp:list` now follow MCP
  cursors and include every page of tools, resources, resource templates and
  prompts. `McpTester` adds symmetric `listResources()`,
  `listResourceTemplates()` and `listPrompts()` helpers.
- The OpenAPI bridge now rejects unsupported URL parameter contracts at build
  time instead of publishing schemas it cannot execute: header/cookie
  parameters, non-scalar or unverifiable schemas, non-default serialization
  styles, `explode` values and `allowReserved=true`.
- Duplicate OpenAPI `operationId` values now throw `InvalidSpecException` with
  both conflicting endpoints instead of silently replacing one operation.
- A request body referenced through `components/requestBodies` now preserves
  its `required` flag in the generated MCP input schema.

## 1.2.0 — 2026-07-16

- Structured tool output documented end-to-end: `outputSchema` on `#[McpTool]`
  is served in `tools/list`, an array return is mirrored into the result's
  `structuredContent` (SDK behavior, now covered by tests, README, llms.txt
  and `examples/structured-output.php`); `Testing\SchemaSnapshot` guards
  output schemas the same way it guards input schemas.
- `Interceptor\ArgumentMasker` — shared sensitive-argument masking helper:
  `password`/`secret`/`token`/`api_key`/`credit_card` keys (configurable,
  case-insensitive) are replaced with `***` at every nesting level. One
  helper for every consumer (audit trail, telemetry, application
  interceptors) so masking semantics do not drift apart.
- `Visibility\DeclarativeToolVisibility` — tool visibility from declarative
  deny/allow name patterns with `*` wildcards, configured in params
  (`'visibility' => ['deny' => ['admin.*'], 'allow' => []]`); deny wins over
  allow, a non-empty allow list hides everything it does not match.
  Mutually exclusive with `tool_visibility` (build-time `LogicException`).
- `mcp:list --json` — full capability definitions (input/output schemas
  included) as normalized JSON (stable item order, sorted object keys) for
  CI diffs and external automation.

## 1.1.0 — 2026-07-05

- OpenAPI bridge: a non-GET operation under `safe_methods_only` now throws
  the dedicated `OpenApi\Exception\UnsafeOperationException` instead of the
  misleading `UnknownOperationException` (the operation IS in the document).
- OpenAPI bridge: an operation declaring a path and a query parameter with
  the same name — or a parameter named `body` alongside a request body — now
  throws `InvalidSpecException` at build time instead of silently collapsing
  two inputs into one tool argument.
- OpenAPI bridge: the `$ref` resolution limit now counts `$ref` hops (max 32
  per chain) instead of plain array nesting — deep schemas without references
  are no longer rejected; ref-to-ref chains resolve fully.
- Documented: external (URL/file) `$ref`s pass through unresolved; an empty
  prompts directory registers no prompts (only a missing one throws).
- `McpServeCommand` accepts an optional `TransportInterface` (a test seam;
  defaults to the stdio transport as before).
- `Testing\McpTester` now joins multi-line SSE `data:` fields per the SSE
  specification instead of reading only the first line.
- `Testing\SchemaSnapshot` fails loudly when the snapshot file cannot be
  fully written (previously a failed or partial write went unnoticed).
- `Interceptor\ToolCallInterceptorInterface` — public extension point wrapping
  every `tools/call` (attribute tools, OpenAPI bridge, configurators);
  configured via the `interceptors` params list (DI-resolved, first =
  outermost). `Interceptor\ToolCallContext` carries the tool name, arguments
  and session (`getClientInfo()`).
- `Interceptor\SessionBudgetInterceptor` — per-session `tools/call` cap
  (`session.budget` param, 0 = unlimited): an agent looping inside one session
  gets an explanatory MCP tool error instead of unlimited calls.
- `Visibility\ToolVisibilityInterface` — per-session tool visibility
  (`tool_visibility` param): `tools/list` omits invisible tools and
  `tools/call` fail-closed rejects them before interceptors and the tool run.
  Complements build-time `ConditionalToolInterface`.
- Multi-tenant serving recipe (rasuvaeff/yii3-tenancy) in the README:
  middleware order, per-tenant session-store isolation, tenant-driven
  visibility; the shared secret stays global (documented trade-off).
- `McpServerFactory::create()` accepts `interceptors` and `toolVisibility`
  (third/fourth arguments, backwards-compatible).
- `configurators` params list: FQCNs implementing
  `ServerConfiguratorInterface`, DI-resolved and applied after the core's own
  prompts/openapi configurators. Generic extension point for companion packages
  and app-specific server setup (mirrors the `interceptors` params pattern).

## 1.0.1 — 2026-07-04

- `McpListCommand` (`mcp:list`) — console introspection of every served
  tool/resource/resource-template/prompt with argument summaries, through the
  same in-process JSON-RPC path a real client uses.
- `Testing\SchemaSnapshot` — contract canary: a committed JSON snapshot of all
  served capability schemas; drift fails the build until the snapshot is
  regenerated deliberately.
- `examples/` now covers every mode: stdio transport, conditional
  registration, Markdown prompts, OpenAPI bridge.
- `ROADMAP.md` — published post-1.0 direction.

## 1.0.0 — 2026-07-04

- Initial release: MCP (Model Context Protocol) server integration for Yii3
  over the official `mcp/sdk`.
- `McpServerFactory` — reads the SDK's `#[McpTool]`/`#[McpResource]` attributes
  off listed tool classes and registers `[class, method]` handlers resolved
  through the Yii3 DI container.
- `McpAction` (PSR-15) serves the Streamable HTTP transport with configurable
  allowed hosts (DNS-rebinding protection); `McpServeCommand` serves stdio.
- `SharedSecretMiddleware` guards the endpoint with clear 401/503 responses.
- `ConditionalToolInterface` for runtime tool gating and
  `ServerConfiguratorInterface` for custom server setup.
- OpenAPI bridge: `OpenApi\OpenApiServerConfigurator` + `OpenApi\SpecLoader`
  expose allow-listed REST operations as MCP tools, with a `safe_methods_only`
  read-only guard.
- `Prompts\MarkdownPromptsConfigurator` — a directory of `*.md` files served as
  MCP prompts.
- `Testing\McpTester` for exercising the server in tests.
- Exceptions in `Exception\`, `OpenApi\Exception\`, and `Prompts\Exception\`.
