# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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
