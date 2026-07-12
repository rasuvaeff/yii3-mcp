# AGENTS.md — yii3-mcp

Guidance for AI agents working on this package. Read before changing code.

## What this is

MCP (Model Context Protocol) server integration for Yii3 over the official
`mcp/sdk` (namespace `Rasuvaeff\Yii3Mcp\`): the application lists tool classes
in params, `McpServerFactory` reads the SDK's `#[McpTool]`/`#[McpResource]`
attributes off their public methods and registers `[class, method]` handlers
so instances are resolved through the Yii3 DI container. `McpAction` (PSR-15)
serves the Streamable HTTP transport; `McpServeCommand` serves stdio;
`SharedSecretMiddleware` guards the endpoint.

Public API: `McpServerFactory`, `McpAction`, `SharedSecretMiddleware`,
`McpServeCommand`, `McpListCommand`, `ConditionalToolInterface`,
`ServerConfiguratorInterface`, `Testing\McpTester`, `Testing\SchemaSnapshot`,
`Interceptor\{ToolCallInterceptorInterface, ToolCallContext,
SessionBudgetInterceptor, InterceptingReferenceHandler, ArgumentMasker}`,
`Visibility\{ToolVisibilityInterface, DeclarativeToolVisibility;
FilteredListToolsHandler is @internal}`,
`OpenApi\{SpecIndex is @internal; OpenApiServerConfigurator,
SpecLoader}`, `Prompts\MarkdownPromptsConfigurator` (file format is
vjik/my-prompts-mcp-compatible — keep it that way), exceptions in
`Exception\`, `OpenApi\Exception\` and `Prompts\Exception\`
(`Testing\SseFrame` is @internal).

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Never invent protocol structures and never weaken fail-closed defaults.**
   Everything protocol-level (attributes, JSON-RPC, transports, sessions)
   comes from `mcp/sdk`; `SharedSecretMiddleware` must keep rejecting every
   request while the secret is empty (explanatory 503 — never a silent
   pass-through), and the shipped session store must stay FPM-safe
   (file-based, never in-memory).
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make: `make build`, `make cs-fix`, `make psalm`, `make test`,
`make test-coverage`, `make mutation`, `make release-check`.

## Invariants & gotchas

- **`mcp/sdk` is pinned `~0.6.0` (tilde, not caret).** The SDK is experimental
  until 1.0; minors are breaking. Bumping the pin is a deliberate act: re-run
  the full test suite (it exercises real SDK behavior end-to-end) and expect
  API drift. After SDK 1.0 → `^1.0` and a major of this package.
- **Session store default must be FPM-safe.** MCP Streamable HTTP sessions
  span requests (`initialize` → `Mcp-Session-Id` → subsequent calls); the
  SDK's `InMemorySessionStore` default silently breaks under PHP-FPM.
  `config/di.php` binds `FileSessionStore`; do not "simplify" it away.
- **`McpServerFactory` reads attributes itself** (reflection over public
  methods) because the SDK's attribute discovery is file-scan based
  (`setDiscovery`), which doesn't fit DI-listed classes. Registration goes
  through `Builder::addTool()/addResource()` with `[class, method]` handlers;
  the SDK's `ReflectedElementLoader` then generates input schemas from
  signatures/DocBlocks and `ReferenceHandler` resolves instances via the
  container. Keep attribute semantics identical to the SDK's own `Discoverer`.
- A configured tool class with no capability attributes throws
  `InvalidToolClassException` at server build time — fail-fast, never a
  silently empty server.
- The SDK requires `ext-fileinfo` and ships the `php-http/discovery` composer
  plugin (set to `false` in `allow-plugins` — we pass PSR-17 factories
  explicitly everywhere, no runtime discovery on our paths). CI extensions
  include `fileinfo` in every job.
- `#[McpTool]` on `GreetingTool::explode` in tests intentionally throws:
  the assertion is that tool failures surface as MCP error envelopes
  (`isError`/`error`), not HTTP 500 with a trace.
- Tests decode both plain-JSON and SSE-framed (`data: ...`) response bodies —
  the Streamable HTTP transport may use either framing.
- Code: `declare(strict_types=1)`, `final readonly class` (except
  `McpServeCommand` — extends symfony Command), `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` references a 40-char commit
  SHA with a `# vN` comment; `permissions: { contents: read }`,
  `persist-credentials: false` on every checkout. Never revert to floating
  tags; verify with `zizmor --persona=auditor .github/`.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
