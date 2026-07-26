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
`ServerConfiguratorInterface`, `ReservedToolNamesAwareInterface`,
`Testing\McpTester`, `Testing\SchemaSnapshot`,
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
- **`SessionBudgetInterceptor`'s counter is deliberately NOT
  concurrency-safe** — a plain `get()`/`set()` read-modify-write, no
  compare-and-swap, because the SDK's `SessionInterface` is a generic
  key-value abstraction over an arbitrary session-store backend with no lock
  primitive to reach for portably. N concurrent requests on the same session
  can overrun the budget by up to N-1. Accepted: it's an anti-loop guard
  against a runaway agent, not a hard quota — do not "fix" this with a
  backend-specific lock (e.g. flock on `FileSessionStore`'s file), which
  would break for any other `SessionStoreInterface` a consumer binds. A real
  per-client quota belongs in an application-level rate limiter with a
  proper atomic store, not here.
- **`CachingToolCallInterceptor`'s cache key MUST include everything the
  result's identity depends on: the resolved client id AND, when
  `openapi.identity_provider` is configured, the resolved
  `ExecutionIdentity`.** A cache shared across distinct clients leaks one
  client's result to another — this is not a configurable trade-off; with
  delegated upstream credentials the identity can be finer-grained than the
  client id (many end users behind one MCP client), so the client id alone
  is NOT enough. A missing client id falls back to `'anonymous'` (stdio),
  never to a shared key. An identity provider failure fails CLOSED for
  cached tools (a cache outage fails open — availability; not knowing whose
  result it is — never). Cached values are wrapped (`['v' => $result]`)
  specifically to distinguish a genuine `null` tool result from a cache
  miss (PSR-16's `get()` returns `null` on both). Keys must stay within
  PSR-16's guaranteed 64 characters — the sha256 digest is truncated to 45
  hex chars for that reason; a longer key makes a strict PSR-16
  implementation throw on every call, silently disabling caching through
  the fail-open catch.
- **Never cut a string with `substr()` on a path that reaches the client.**
  Everything this package truncates (an over-limit tool result in
  `ResponseSizeLimitInterceptor`, the upstream error-body excerpt in
  `HttpOperationExecutor`) ends up inside a JSON-RPC response the SDK encodes
  with `JSON_THROW_ON_ERROR`; a half-written multi-byte character makes that
  encode fail, and `Mcp\Server\Protocol::queueOutgoing()` then **drops the
  response silently** (only the sessionless stdio path gets an
  `INTERNAL_ERROR` fallback, so the failure is invisible on Streamable HTTP).
  Use `Utf8::cut()` (`@internal`, byte-wise on purpose — `mb_strcut` would
  pull `ext-mbstring` into the package requirements and into every CI job's
  extension list for two call sites). `cut()` only avoids SPLITTING a
  character; it does not repair input that was never UTF-8, so a foreign body
  (a legacy-encoded upstream error page) is validated separately with
  `preg_match('//u', …)` and replaced by a byte-count placeholder. Tests must
  assert with PCRE, not `mb_check_encoding` — mbstring is absent from CI.
- **`tag:` is a reserved prefix in `DeclarativeToolVisibility` patterns.**
  A pattern starting with `tag:` matches the tool's tags (`_meta['rasuvaeff/yii3-mcp']['tags']`,
  populated by the OpenAPI bridge from OpenAPI `tags`) instead of its name;
  the prefix is stripped once during `compile()`, so a tag pattern must
  never ALSO get compiled as a name pattern (that bug already happened once —
  keep the early `continue` after appending the tag-kind entry). Tool names
  containing a literal `tag:` are indistinguishable from the prefix — none
  exist in this ecosystem, and this is an accepted, documented trade-off.
  Note the trust boundary: tags come from the OpenAPI document, so over a URL
  spec a `tag:`-based DENY rule can be disarmed by the upstream dropping the
  tag (exposure stays bounded by the `operations` allow-list). Documented in
  both READMEs — deny by name over a remote spec, keep `tag:` for allow-lists
  and local files.
- **`OpenApi\Operation` is `@api` (promoted from `@internal`) specifically to
  serve as the read-only context passed to `OperationModifierInterface::modify()`.**
  It stays a small readonly VO — do not grow it into a general-purpose object;
  add fields only when the modifier genuinely needs them.
- **Tool names must be unique across the WHOLE server, and the SDK will not
  tell you otherwise.** `Registry::registerTool()` is last-write-wins with no
  duplicate check, and `Builder::build()` runs its explicit loader
  (`Builder::add()`, used by configurators) BEFORE the reflected one
  (`Builder::addTool()`, used for `#[McpTool]` methods) — so on a collision
  the attribute tool wins and the **bridged tool silently disappears** from
  `tools/list`, which was verified empirically, not inferred. `McpServerFactory`
  therefore collects the attribute tools' names (mirroring the SDK's own rule:
  the attribute's `name`, else the method name, else the class short name for
  `__invoke`) and hands them to every configurator implementing
  `ReservedToolNamesAwareInterface` before `configure()`;
  `OpenApiServerConfigurator` seeds its `$usedNames` map with them so a
  `tool_names`/modifier rename onto a taken name fails fast. If the SDK's
  naming rule drifts on a pin bump, `reservedNamesFollowTheSdkDefaultNamingRule`
  is the test that catches it.
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
  has no `additionalProperties: false` guard. On a dry-run-ENABLED operation
  a present-but-non-boolean `dryRun` value throws instead of executing for
  real — the SDK's schema validation rejects it first, but the executor's
  own failure direction must stay safe (a malformed preview intent must
  never become a real write). The preview is returned as a
  plain string (never an array), specifically so it never becomes
  `structuredContent` and contradicts the operation's declared
  `outputSchema`; it never includes headers, since those may carry
  server-side credentials the caller never supplied — and the executor
  rejects a base URL with embedded credentials (userinfo) or a query
  string/fragment at construction, since the preview exposes the full URL.
  Dry-run does not relax
  `safeMethodsOnly` — a write operation still needs it disabled to be
  exposed, dry-run or not.
- **Bridged path arguments reject `""`, `"."`, and anything CONTAINING `..`,
  `/` or `\`.** `rawurlencode` keeps dots verbatim and encodes `/` as `%2F`,
  which upstreams that decode before normalizing the path (Apache with
  `AllowEncodedSlashes`, some proxies and servlet containers) hand back as a
  real separator — so equality checks against `.`/`..` alone were narrower
  than the threat they document: `../..` and `x/..` survived them and could
  climb out of the allow-listed route with the bridge's credentials. An empty
  value is the same escape one level up (`/users/` is typically the
  collection route, not the allow-listed item route). Accepted trade-off: a
  legitimate identifier containing `..` (`a..b`) cannot be bridged as a path
  argument; single dots still pass (`v1.2`). The check lives in
  `HttpOperationExecutor::buildPath()`; do not "simplify" it back to equality
  comparisons.
- **An operation's static path (the OpenAPI Path Item Object key) must start
  with `/`; `SpecIndex` silently drops any operation whose path doesn't.**
  `HttpOperationExecutor` builds the request URL as `$baseUrl . $path` with no
  separator — a path missing the leading slash (e.g. `evil.com/x`) splices
  into the host string (`https://api.test` + `evil.com/x` =
  `https://api.testevil.com/x`, a different host, sent with this bridge's
  delegated credentials). The OpenAPI spec itself requires the leading slash,
  so rejecting a path without one only enforces the spec, not an extra
  restriction. Checked in `SpecIndex::buildOperation()`, same fail-closed
  shape as the existing empty-`operationId`/empty-`path` guard.
- **`mcp/sdk` is pinned `~0.7.0` (tilde, not caret).** The SDK is experimental
  until 1.0; minors are breaking. Bumping the pin is a deliberate act: re-run
  the full test suite (it exercises real SDK behavior end-to-end) and expect
  API drift. After SDK 1.0 → `^1.0` and a major of this package. The 0.6.0 →
  0.7.0 bump was verified empirically (full build + mutation + bc-check, not
  just a changelog read) before merging — every class this package depends on
  (`Tool`, `ToolAnnotations`, `Registry`, `NameValidator`) was byte-for-byte
  unchanged; do the same verification on any future bump, don't assume a minor
  is safe from the changelog alone.
- **The SDK's `DnsRebindingProtectionMiddleware::isAllowedHost()` does not
  strip a trailing dot from an FQDN.** A client sending `Host:
  app.example.com.` (trailing dot — legal DNS, some clients/resolvers add it)
  gets a 403 even when `app.example.com` is allow-listed. This lives in
  `mcp/sdk`, not in this package; `Doctor\McpDoctor`'s `checkAllowedHost`
  mirrors the SDK's port-stripping but cannot compensate for an SDK-side gap
  it doesn't own. Not something to "fix" here.
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
