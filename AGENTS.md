# AGENTS.md — yii3-mcp

Guidance for AI agents working on this package. Read before changing code.

## What this is

Yii3 package over the framework-neutral `rasuvaeff/mcp` runtime (namespace
`Rasuvaeff\Mcp`). It owns the Yii3 side only: `config/di.php` + `config/params.php`
(`yiisoft/config` plugin), the params interpreter `McpServerComponentResolver`
(@internal), `McpDoctorCommand` with `Doctor\*`, and, until it moves to its own
package, the OpenAPI bridge (`OpenApi\*`). Rules about the runtime (interceptors,
visibility, sessions, OAuth, eras) are in the `rasuvaeff/mcp` `AGENTS.md`: change
them there, not here.

Public API: `McpDoctorCommand`,
`Doctor\{McpDoctor, DoctorReport, CheckResult, CheckStatus, CheckCategory}`,
`OpenApi\{OpenApiBridgeFactory, OpenApiServerConfigurator, SpecLoader, Operation,
OperationModifierInterface, DelegatedHeaderProviderInterface,
ExecutionRequestAttributesInterface, InProcessScopeInterface}` and
`OpenApi\Exception\*`; the rest of `OpenApi\` is @internal.

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

- **Local 6.0 work before `rasuvaeff/mcp` is published** uses
  `COMPOSER=composer.local.json` (untracked, path repository on `../mcp`).
- **A non-empty `prompts_path` fails the build** (Markdown prompts were removed
  in 6.0): ignoring it would drop prompts silently.
- **`mcp:serve`/`mcp:list`/`mcp:doctor` are registered through the package's
  `yiisoft/yii-console` params** — the contribution is a contract tested by
  `ConfigWiringTest::consoleCommandsAreRegisteredThroughYiiConsoleParams`, not
  prose. `yiisoft/yii-console` is deliberately NOT a dependency: the params key
  is inert in an application without the console component. Any new console
  command joins that same block; do not move registration into `di.php`.
- **The two OpenAPI executors share `OperationRequestBuilder` and
  `OperationResponseDecoder` and must never grow transport-specific logic that
  the other one misses.** Everything a caller can observe before the send
  (path guards, dry-run preview semantics, delegated headers, body encoding)
  and after it (size cap, error excerpt, `opaque_errors`, JSON/plain fallback)
  lives in the shared classes; `HttpOperationExecutor` and
  `Psr15OperationExecutor` differ ONLY in how the built plan reaches the
  application (PSR-18 `sendRequest` vs a `ServerRequest` +
  `$handler->handle()`). A guard added to one path and not the other is a bug
  even if both test suites stay green — their unit suites deliberately mirror
  each other.
- **`Psr15OperationExecutor` resolves the `ExecutionIdentity` once per call**
  (inside the builder's `plan()`): delegated headers AND request attributes
  derive from the same resolution, because a provider that reads request state
  need not be idempotent — `CountingIdentityProvider` in the test suite is the
  regression guard. `inProcessScope->leave()` runs in a `finally`: it fires
  even when the nested handler throws, and only ever after `enter()` of the
  same invocation. Re-entrancy of the application's own stack is the HANDLER's
  contract — the package provides the `enter()`/`leave()` hook, not the reset
  itself; `psr15` without `handler`, psr15-only keys in `http` mode or an
  unsupported `executor` value fail the server build in
  `McpServerComponentResolver` (and the same validation set is mirrored by
  `OpenApiBridgeFactory::create()` for standalone consumers).

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
- **`/` is the ONLY thing `multi_segment_path_params` buys, and only for the
  parameters named in it.** Some upstreams identify a resource by a nested
  path and accept it percent-encoded (GitLab: `group/project` →
  `group%2Fproject`), so a parameter can be opted into N `"/"`-separated
  segments. Everything else in the rule above still applies to an opted-in
  value — `..` anywhere, a backslash anywhere, an empty value, a bare `.` —
  and each segment must additionally match
  `[A-Za-z0-9_][A-Za-z0-9_.-]*`. Two knobs are deliberately absent: the
  charset and the ceiling of 20 on the limit. The reason is the residual risk
  the opt-in accepts and the cap alone bounds: a multi-segment value no
  longer pins the request to the allow-listed route (`1/repository/archive`
  under `GET /projects/{id}` reaches an operation `operations` never
  exposed). An operator writing a regex would drop the cap without noticing;
  an integer cannot be written without it. The default is an empty list, so
  the single-segment rule above is what every deployment gets until someone
  opts a parameter in — `routeEscapingPathArgumentIsRejected` runs against a
  non-opted parameter and is the regression guard for that.
- **`OpenApi\OpenApiBridgeFactory` is the ONE construction path for the
  bridge, and the reason `SpecIndex`/`HttpOperationExecutor` can stay
  `@internal`.** Psalm's `@internal` is namespace-scoped, so a factory living
  in `Rasuvaeff\Yii3Mcp\OpenApi` may construct both while a consumer outside
  `Rasuvaeff\` touches only the factory — which is what makes the bridge a
  package feature rather than a Yii3-application one (a consumer has no legal
  way to silence `InternalClass`: no baseline, no suppressions). Do NOT
  promote either class to `@api` to "fix" a consumer; add a named argument to
  the factory instead. `McpServerComponentResolver` calls it too — keep it
  that way, or the config path and a standalone server drift apart. New
  arguments go LAST and optional: the factory is `@api` and BC-checked.
- **Everything a bridged call's caller reads names the SERVED tool, and it
  only reaches the caller because `BridgedToolHandler` rethrows.** The served
  name (post-`tool_names`, post-modifier) is passed into
  `HttpOperationExecutor::execute()` as the last optional argument and used in
  every message; the operationId is what the rename hid from the agent, so
  quoting it back gives it an identifier in no tool list. Two things are
  deliberate and must not be "simplified": (1) `BridgedToolHandler` catches
  `OperationFailedException` and `Exception\InvalidToolArgumentException` and
  rethrows them as the SDK's `ToolCallException` — `CallToolHandler` turns
  ONLY that type into a tool-error envelope carrying the message and replaces
  every other Throwable with `Error::forInternalError('Error while executing
  tool')`, dropping the text entirely (which also made `opaque_errors`
  meaningless, since nothing reached the caller either way). **Those two types
  are the entire allow-list — never widen it back to the bare
  `InvalidArgumentException`.** The PSR-17/PSR-18 stack raises its own:
  an unparseable request URI, a delegated header name that is not RFC 7230
  compatible. Their messages quote the base URL or the offending header, so
  catching the parent forwards deployment detail to the agent AND mislabels an
  infrastructure fault as a problem with the arguments it sent. That is why
  every caller-argument guard in `HttpOperationExecutor::execute()` throws the
  dedicated type while the CONSTRUCTOR's config guards keep the bare one —
  they run at build time and never reach this catch;
  `infrastructureFailuresDoNotReachTheClient` is the regression guard; (2) the dry-run
  preview payload still reports `operationId` — it documents the upstream
  request, and that is the id a caller looks up in the OpenAPI document.
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
- **`mcp/sdk` is pinned `~0.10.0` (tilde, not caret).** There is no separately
  maintained 4.x line. The 0.8 → 0.10 bump (5.0) moved every SDK class
  (`Mcp\Server\Server`, `Mcp\Server\Capability\*`, `Mcp\Server\Exception\*`,
  `Mcp\Schema\RequestMeta`), added `SessionInterface::isUnreadable()` and
  `RegistryInterface::publishesListChanges()` (`GuardedRegistry` forwards it),
  made a missing `Host` a 403, bumped `MessageInterface::PROTOCOL_VERSION` to
  2026-07-28 (the tester uses `ProtocolVersion::latestHandshake()`), refuses
  `tools/call` arguments that are not a JSON object, keeps nested JSON
  objects as `\stdClass` (`ArgumentMasker` and the cache key walk them),
  encodes results compactly and suspends with `NotificationSuspension`
  objects. The SDK is experimental
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
  stores there — corrected by `stubs/Tool.phpstub` (registered in
  `psalm.xml`), NOT by a suppression: the stub declares the honest accepted
  type (`array<string, mixed>|\stdClass` for `properties`). Revisit the stub
  whenever the SDK pin moves.
- **Six classes are covered by property tests, and the properties are the
  contract — not the examples.** `Utf8::cut()` (budget, encodability, prefix
  relation), `OpenApi\ToolNameValidator` (charset + length; stated
  independently of the class's own regex, which is what makes it able to catch
  a `\z` → `$` regression), `OpenApi\JsonPointerResolver` (depth/cycle
  termination — `timeoutMs` is mandatory there, a hung guard must fail CI, not
  hang it), `Interceptor\ArgumentMasker` (idempotence, no sensitive value at
  any depth, structure preserved), `Visibility\DeclarativeToolVisibility`
  (deny beats allow, monotonicity) and `Interceptor\ToolCallBudgetInterceptor`
  (model-based for both eras: `tests/Support/SessionBudget/` and
  `tests/Support/StatelessBudget/` — clients × windows × clock — sequential
  only, see the concurrency note above). Generators live in `<method>Generators()`, **public
  static** (rector rewrites a private static helper called from a property
  body, which silently unhooks the provider — it surfaces on `release-check`,
  not `build`). Every property carries `Classify::cover` gates: a generator
  that drifts and stops reaching a branch fails loudly instead of passing
  vacuously. CI caches the regression corpus (`PROPERTY_DB`) with split
  `actions/cache/restore` + `save`; do not collapse them back into the
  combined action, whose `post-if: success()` never saves on the red run that
  found the counterexample.
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
- Keep `resources/skills/rasuvaeff-yii3-mcp/SKILL.md` current in the same
  commit: it ships to consumers' agents (`llm/skills`) and states the safety
  rules they follow — a stale rule there is followed, not ignored.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
