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
`McpServeCommand`, `McpListCommand`, `McpDoctorCommand`,
`Doctor\{McpDoctor, DoctorReport, CheckResult, CheckStatus, CheckCategory}`,
`Identity\{SecretResolverInterface, StaticSecretResolver;
ClientIdentityContext is @internal}`,
`ConditionalToolInterface`,
`ServerConfiguratorInterface`, `Testing\McpTester`, `Testing\SchemaSnapshot`,
`Interceptor\{ToolCallInterceptorInterface, ToolCallContext,
PromptGetInterceptorInterface, PromptGetContext,
ResourceReadInterceptorInterface, ResourceReadContext, CallOutcome,
SessionBudgetInterceptor, ResponseSizeLimitInterceptor,
CachingToolCallInterceptor, InterceptingReferenceHandler, ArgumentMasker,
ToolCallLimiterInterface, RateLimitInterceptor}`,
`Visibility\{ToolVisibilityInterface, DeclarativeToolVisibility,
PromptVisibilityInterface, ResourceVisibilityInterface;
FilteredListToolsHandler, FilteredListPromptsHandler,
FilteredListResourcesHandler, FilteredListResourceTemplatesHandler are
@internal}`,
`OpenApi\{SpecIndex, ToolNameValidator are @internal; OpenApiServerConfigurator,
SpecLoader, Operation, OperationModifierInterface, ExecutionIdentity,
ExecutionIdentityProviderInterface, DelegatedHeaderProviderInterface}`,
`Prompts\MarkdownPromptsConfigurator` (file format is
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

- **Interceptor chain order is fixed and load-bearing:** session budget
  (outermost) → configured `interceptors` → `CachingToolCallInterceptor` →
  `ResponseSizeLimitInterceptor` (innermost). Caching must sit BETWEEN user
  interceptors and the size limit, never around user interceptors — RBAC/
  audit must run on every call including a cache hit, or caching becomes an
  ACL bypass. Never reorder without preserving this.
- **`CachingToolCallInterceptor`'s cache key MUST include the resolved
  client id.** A cache shared across distinct clients leaks one client's
  result to another — this is not a configurable trade-off. A cache miss
  falls back to `'anonymous'` (stdio has no client id), never to a shared
  key. Cached values are wrapped (`['v' => $result]`) specifically to
  distinguish a genuine `null` tool result from a cache miss (PSR-16's
  `get()` returns `null` on both).
- **`tag:` is a reserved prefix in `DeclarativeToolVisibility` patterns.**
  A pattern starting with `tag:` matches the tool's tags (`_meta['rasuvaeff/yii3-mcp']['tags']`,
  populated by the OpenAPI bridge from OpenAPI `tags`) instead of its name;
  the prefix is stripped once during `compile()`, so a tag pattern must
  never ALSO get compiled as a name pattern (that bug already happened once —
  keep the early `continue` after appending the tag-kind entry). Tool names
  containing a literal `tag:` are indistinguishable from the prefix — none
  exist in this ecosystem, and this is an accepted, documented trade-off.
- **`OpenApi\Operation` is `@api` (promoted from `@internal`) specifically to
  serve as the read-only context passed to `OperationModifierInterface::modify()`.**
  It stays a small readonly VO — do not grow it into a general-purpose object;
  add fields only when the modifier genuinely needs them.
- **A tool-name change is validated identically wherever it happens.** Both a
  `tool_names` rename and an `OperationModifierInterface`-returned name reuse
  `OpenApi\ToolNameValidator` (`@internal`, shared with `SpecIndex`) and are
  checked for collisions against the SAME `$usedNames` map, evaluated only
  ONCE — after the modifier ran, on the final served name. Do not
  reintroduce a pre-modifier collision check: it would reject configurations
  where the modifier's rename no longer collides, and miss ones where it
  newly does.
- **OpenAPI bridge dry-run is fail-closed by construction, not by config.**
  `OpenApiServerConfigurator`'s `dryRunOperations` list decides which
  operations get the extra `dryRun` inputSchema argument; the actual guard is
  a SEPARATE boolean threaded from `BridgedToolHandler` into
  `HttpOperationExecutor::execute()`, checked against the operation itself —
  a client cannot smuggle `dryRun: true` into a non-enabled operation's
  arguments and get a preview instead of a real call, since the input schema
  has no `additionalProperties: false` guard. The preview is returned as a
  plain string (never an array), specifically so it never becomes
  `structuredContent` and contradicts the operation's declared
  `outputSchema`; it never includes headers, since those may carry
  server-side credentials the caller never supplied. Dry-run does not relax
  `safeMethodsOnly` — a write operation still needs it disabled to be
  exposed, dry-run or not.
- **`mcp/sdk` is pinned `~0.7.0` (tilde, not caret).** The SDK is experimental
  until 1.0; minors are breaking. Bumping the pin is a deliberate act: re-run
  the full test suite (it exercises real SDK behavior end-to-end) and expect
  API drift. After SDK 1.0 → `^1.0` and a major of this package. The 0.6.0 →
  0.7.0 bump was verified empirically (full build + mutation + bc-check, not
  just a changelog read) before merging — every class this package depends on
  (`Tool`, `ToolAnnotations`, `Registry`, `NameValidator`) was byte-for-byte
  unchanged; do the same verification on any future bump, don't assume a minor
  is safe from the changelog alone.
- **Empty JSON Schema `properties` must serialize as `{}`.** The SDK
  normalizes `[]` → `\stdClass` only inside `Tool::fromArray()`; the OpenAPI
  bridge builds `Tool` directly, so `InputSchemaBuilder` does it explicitly and
  `SpecIndex` omits an empty `properties` from `outputSchema`. `[]` on the wire
  makes clients reject the entire `tools/list` (`expected record, received
  array`). The SDK's own `ToolInputSchema` docblock contradicts what the SDK
  stores there, so `psalm.xml` suppresses `ArgumentTypeCoercion` for
  `Mcp\Schema\Tool::__construct` — the package's only suppression; still
  present in 0.7.0 (confirmed by temporarily removing it), revisit whenever
  the pin moves again.
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
- **`Identity\ClientIdentityContext` is a deliberate process-local mutable
  static** (the only one in this package): the SDK hands its reference
  handler the JSON-RPC request, not the PSR-7 one, so the client id resolved
  by `SharedSecretMiddleware` cannot travel as a request attribute all the
  way down. `McpAction` arms it before `Server::run()` and disarms in a
  `finally`; FPM-safe because a worker handles one request at a time. Do not
  read it outside `InterceptingReferenceHandler`, and never store the raw
  secret in it.
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

- Update `README.md` AND `README.ru.md` — the README is bilingual, every
  change lands in both files in the same commit (and `examples/` if usage
  changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
