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
`Session\{PrivateFileSessionStore; SessionDirectory is @internal}`,
`GuardedRegistry is @internal`,
`ConditionalToolInterface`,
`ServerConfiguratorInterface`, `ReservedToolNamesAwareInterface`,
`Testing\McpTester`, `Testing\McpErrorException`, `Testing\SchemaSnapshot`,
`Interceptor\{ToolCallInterceptorInterface, ToolCallContext,
PromptGetInterceptorInterface, PromptGetContext,
ResourceReadInterceptorInterface, ResourceReadContext, CallOutcome,
ToolCallBudgetInterceptor, ResponseSizeLimitInterceptor,
CachingToolCallInterceptor, InterceptingReferenceHandler, ArgumentMasker,
ToolCallLimiterInterface, RateLimitInterceptor, ToolResultDecoratorInterface;
ClientInfoResolver and
RequestEra are @internal}`,
`Visibility\{ToolVisibilityInterface, DeclarativeToolVisibility,
PromptVisibilityInterface, ResourceVisibilityInterface;
FilteredListToolsHandler, FilteredListPromptsHandler,
FilteredListResourcesHandler, FilteredListResourceTemplatesHandler,
FilteredCallToolHandler, FilteredCompletionCompleteHandler are @internal}`,
`OpenApi\{SpecIndex, ToolNameValidator, JsonPointerResolver,
OutputSchemaProjector, OperationContractValidator are @internal;
OpenApiBridgeFactory, OpenApiServerConfigurator,
SpecLoader, Operation, OperationModifierInterface, ExecutionIdentity,
ExecutionIdentityProviderInterface, DelegatedHeaderProviderInterface,
ExecutionRequestAttributesInterface, InProcessScopeInterface}`,
`Apps\{McpAppsConfigurator, AppDefinition; AppParamParser and
AppResourceHandler are @internal}`,
`Resource\ResourceUpdateNotifier`,
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

- **`mcp:serve`/`mcp:list`/`mcp:doctor` are registered through the package's
  `yiisoft/yii-console` params** — the contribution is a contract tested by
  `ConfigWiringTest::consoleCommandsAreRegisteredThroughYiiConsoleParams`, not
  prose. `yiisoft/yii-console` is deliberately NOT a dependency: the params key
  is inert in an application without the console component. Any new console
  command joins that same block; do not move registration into `di.php`.
- **`result_json => 'compact'` converts the tool result in
  `InterceptingReferenceHandler::handleTool()`, AFTER the interceptor chain and
  BEFORE the SDK's `CallToolHandler`** — the SDK skips both its pretty formatter
  and `extractStructuredContent()` for a reference handler that already returns
  a `CallToolResult`, which is the entire mechanism. The placement is
  load-bearing: interceptors (RBAC/audit), the cache and the size limit must
  keep seeing the RAW handler result they were written against.
  `CompactToolResultFormatter` (@internal) must stay a branch-for-branch mirror
  of the SDK's `ToolResultFormatter::format()` TEXT branches (Content
  pass-through, mixed-array per-item formatting, scalar/null/bool sentinels,
  `JSON_INVALID_UTF8_SUBSTITUTE`); re-diff it on every SDK pin bump.
  `structuredContent` is NOT mirrored but delegated to
  `ToolReference::extractStructuredContent($result, $protocolVersion)`: since
  SDK 0.8 the rule depends on the revision (a list is structured content only
  from 2026-07-28 on; before it strict clients reject the whole call). The 0.7
  copy of those rules drifted the moment 0.8 changed them —
  `structuredContentAgreesWithTheSdk` compares the two per revision. A ready
  `InputRequiredResult` (multi round-trip ask) passes through unformatted.
  Its property test compares the text with `json_encode(structuredContent)` at
  the ENCODING level on purpose: PHP decodes JSON numbers without a decimal
  point as ints, so `0.0` does not survive `json_decode(json_encode(0.0))` as a
  float — true for the SDK's pretty path alike (PHP 8.5 encodes `0.0` as `0`).
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

- **Interceptor chain order is fixed and load-bearing:** tool-call budget
  (outermost) → configured `interceptors` → `CachingToolCallInterceptor` →
  `ResponseSizeLimitInterceptor` (innermost). Caching must sit BETWEEN user
  interceptors and the size limit, never around user interceptors — RBAC/
  audit must run on every call including a cache hit, or caching becomes an
  ACL bypass. Never reorder without preserving this.
- **Result decorators run AFTER the chain and AFTER formatting, and never
  change anything by their mere presence.** `InterceptingReferenceHandler`
  formats first (compact mode: `CompactToolResultFormatter`; pretty mode with
  decorators: the SDK's own `formatResult()` + `extractStructuredContent()`
  for the request's revision — a verbatim copy of `CallToolHandler`'s
  branch), then hands the `CallToolResult` to each decorator in order. Pretty
  mode WITHOUT decorators still returns the raw result and lets the SDK
  format — never build it in-package unconditionally. An
  `InputRequiredResult` returns before formatting and is never decorated.
  `McpServerFactory::create()` installs the handler when decorators alone are
  configured (otherwise they would be silently ignored). The regression guard
  is `ToolResultDecoratorTest::anIdentityDecoratorChangesNothing` — every
  result shape × both eras × both `result_json` modes compared whole against
  a server without decorators; re-run it on every SDK pin bump, it is what
  proves the pretty copy still matches the SDK.
- **`ToolCallBudgetInterceptor` counts in two places, by era, and the
  stateless one is the reason the class exists.** Handshake era: in the
  session (initialize → TTL). Stateless 2026-07-28 era: the SDK hands every
  request a THROWAWAY session (`StatelessProtocol` builds
  `new Session(new InMemorySessionStore())`), so a session counter starts at
  zero on every call and the guard silently never fires — that was 3.x's
  `SessionBudgetInterceptor` under SDK 0.8. There the budget is per client id
  in PSR-16 over a FIXED window: `floor(now / window)` is part of the key and
  the TTL runs to the end of the window. Never key on the client alone with
  `ttl = window`: PSR-16 `set()` re-arms the TTL on every write, so a client
  that keeps calling would never be reset. A null client id is one shared
  bucket (stricter, on purpose). Missing cache or a throwing backend rejects
  the call (fail-closed, like `RateLimitInterceptor`). The resolver resolves
  `CacheInterface` ONLY when the stateless era is on (3.0.1 was the same class
  of bug with PSR-18), and `session.budget` (the 3.x key) fails the build —
  the package's own params must therefore never ship that key. Era detection
  lives in `Interceptor\RequestEra` only.
- **A multi round-trip call is several `tools/call`s, and each round re-enters
  the whole chain.** On the stateless era `elicit()` suspends the handler
  Fiber; the SDK abandons it, answers `input_required`, and on the client's
  retry runs the handler from the top (`ElicitationReplay`). Consequences kept
  on purpose: the budget counts every round (answers are unsigned — a budget
  skipping retries is bypassed by attaching made-up `inputResponses`);
  `CachingToolCallInterceptor` neither stores an `InputRequiredResult` (it
  would answer the retry carrying its own answer, forever) nor reads/writes
  on a retry (`RequestEra::isRetry()` — the arguments do not show the
  answers). More than one ask needs `request_state.key`.
  `tests/MultiRoundTripTest` drives it end to end through the tester.
- **`cache_policy` `public` is refused on per-caller answers.** With any
  visibility filter, `tools/list`/`prompts/list`/`resources/list`/
  `resources/templates/list`/`resources/read` depend on the caller; a
  `public` SEP-2549 hint would let a shared proxy serve one caller's view to
  another, so `McpServerFactory::create()` throws. `CachePolicyParams` fails
  config load on a method that carries no hint (a typo would be a hint never
  sent) — do not loosen either into a warning.
- **Neither budget counter is concurrency-safe** — a plain `get()`/`set()`
  read-modify-write, no compare-and-swap, because neither the SDK's
  `SessionInterface` nor PSR-16 exposes one. N concurrent requests can
  overrun the budget by up to N-1. Accepted: it's an anti-loop guard
  against a runaway agent, not a hard quota — do not "fix" this with a
  backend-specific lock (e.g. flock on `FileSessionStore`'s file), which
  would break for any other `SessionStoreInterface` a consumer binds. A real
  per-client quota belongs in an application-level rate limiter with a
  proper atomic store, not here.
- **`CachingToolCallInterceptor`'s cache key MUST include everything the
  result's identity depends on: the mandatory application namespace, the
  resolved client id AND, when `openapi.identity_provider` is configured,
  the resolved `ExecutionIdentity`.** A cache shared across distinct clients
  leaks one client's result to another — this is not a configurable
  trade-off; with delegated upstream credentials the identity can be
  finer-grained than the client id (many end users behind one MCP client),
  so the client id alone is NOT enough. The key material is a typed JSON
  structure carrying a format version — an ABSENT client id encodes as
  `null`, never as a sentinel string a real client id (`anonymous`) could
  collide with; the namespace (default: `server_name`) isolates
  applications sharing one cache backend. An identity provider failure
  fails CLOSED for
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
  `preg_match('//u', …)` and replaced by a byte-count placeholder. Tests assert
  with PCRE, not `mb_check_encoding`. `ext-mbstring` IS in the CI extension
  lists now (property-testing requires it), so that no longer fails outright —
  which is exactly why the rule has to be stated rather than enforced by
  accident: production code and its tests must not imply a requirement this
  package deliberately does not declare.
- **A handler that emits a notification cannot be asserted through
  `Testing\McpTester`.** The moment anything suspends the Fiber to send
  (progress, client logging, `notifications/resources/updated`), the SDK's
  `StreamableHttpTransport` switches to streaming and `echo`es the SSE frames
  straight to output with `ob_flush()`; the PSR-7 response body stays empty and
  even an `ob_start()` around the call captures nothing. Test such handlers by
  running them inside `new Fiber(...)` and asserting the suspend value — that is
  exactly what the transport would flush. Do not "fix" the tester by parsing
  output buffers.
- **A `text/event-stream` response leaves `McpAction` wrapped in
  `OutputBufferReleasingStream` (@internal), and the wrapper must stay.** The
  SDK's stateless `StatelessResponder::sse()` (0.8.1) writes frames with
  `flush()` but no `ob_flush()`; the `output_buffering` buffer PHP opens before
  the application (4096 by default) and `yiisoft/psr-emitter` (ends only buffers
  above the level it recorded) would hold a whole `subscriptions/listen`
  stream until it closes (#74). The wrapper ends buffers right before the
  SDK's callback writes; it never reads the stream itself. Fixed upstream
  (modelcontextprotocol/php-sdk#520, `@ob_flush()` in `sse()`, merged
  2026-10-05, not in 0.8.1): drop the wrapper once the pin includes it.
- **`Resource\ResourceUpdateNotifier` has two delivery paths, one per era,
  and the SDK joins them nowhere.** The notification bus (SDK 0.8) feeds ONLY
  stateless `subscriptions/listen` streams; handshake sessions still get an
  update only through their own `ClientGateway`, i.e. only the CALLING session,
  after `SubscriptionManagerInterface::isSubscribed()` (so nothing unsolicited
  reaches the wire). `notify($uri, ?$context)` publishes to the bus whenever
  one is bound — without a context too (queue worker) — and skips the gateway
  for a stateless context (its session is a throwaway). The bus is bound in
  `config/di.php` only when `notifications.bus` is set, and BOTH
  `McpServerFactory` and the notifier take it as an optional dependency: a
  memory bus split into two instances would publish where nobody reads. `config/di.php` binds the manager so the SDK's
  subscribe handler and the notifier read the same state; a consumer swapping the
  binding must get both sides, which is why `McpServerFactory` forwards it to
  `Builder::setResourceSubscriptionManager()` instead of letting them diverge.
- **`*ListChanged` capabilities are deliberately NOT enabled.** Passing an
  event dispatcher to the SDK flips `toolsListChanged`/`promptsListChanged`/
  `resourcesListChanged` to `true`, but the SDK ships no listener that turns the
  dispatched `*ListChangedEvent`s into `notifications/*/list_changed` messages,
  and registry mutations happen at BUILD time, before any client is connected.
  Enabling it would advertise a capability the server never honours — the exact
  mistake `resources/subscribe` used to make here. Revisit only if the SDK grows
  the listener.
- **Two eras on one endpoint, and `initialize` negotiates (since SDK 0.8).**
  Handshake era: the client's revision when supported, else the newest
  handshake one (`MessageInterface::PROTOCOL_VERSION`); `protocol_version`
  pins one HANDSHAKE revision (`config/di.php` rejects 2026-07-28 — it has no
  initialize). Stateless era (`modern_era`, default on): the SDK classifies
  each request by `params._meta`; there is no session, the SDK builds a
  throwaway one per request. Do not hardcode a revision anywhere —
  `Testing\McpTester` defaults to that constant (they disagreed once: the
  tester claimed 2025-06-18 while the server answered 2025-11-25) and takes a
  revision for era-specific tests. Anything session-bound must be tested on
  both eras; that is how the 0.8 bump found the budget, ownership and
  `getClientInfo()` holes.
- **`completion/complete` does not go through the reference handler, so
  visibility has to be applied to it separately.** The SDK's
  `CompletionCompleteHandler` reads the registry directly; before
  `Visibility\FilteredCompletionCompleteHandler` existed, a prompt hidden by
  `PromptVisibilityInterface` still returned completions for its arguments —
  verified end-to-end, not inferred — leaking the values AND the capability's
  existence (hidden answered, missing errored). The decorator wraps the SDK
  handler rather than reimplementing provider resolution, and phrases its
  refusal with the SDK's own `PromptNotFoundException` /
  `ResourceNotFoundException` message AND the SDK's code (`-32602` since 0.8;
  answering the old `-32002` kept the text identical and still made the code
  an existence oracle) so a hidden ref stays byte-identical to a missing one.
  `tools/call` has the same problem one step earlier:
  `Visibility\FilteredCallToolHandler` answers a hidden tool before the SDK
  validates arguments against its schema (an invalid call would otherwise
  reveal it) — the reference-handler check stays as defence in depth.
  `tests/Visibility/HiddenIsMissingTest` compares whole error envelopes for
  every capability method; keep new methods in it. A ref that does not
  resolve at all is passed through to the inner handler — never phrase "unknown capability" in two places. If the SDK
  ever routes completion through the reference handler, drop the decorator
  instead of stacking two checks.
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
- **Capability identities must be unique across the WHOLE server, and the
  SDK will not tell you otherwise.** `Registry::registerTool()` is
  last-write-wins with no duplicate check, and `Builder::build()` runs its
  explicit loader (`Builder::add()`, used by configurators) BEFORE the
  reflected one (`Builder::addTool()`, used for `#[McpTool]` methods) — so
  on a collision the attribute tool wins and the **bridged tool silently
  disappears** from `tools/list`, which was verified empirically, not
  inferred. Two layers of defence, keep BOTH:
  1. `McpServerFactory` always installs `GuardedRegistry` (@internal, wraps
     the SDK registry via `Builder::setRegistry()`) — the single point every
     registration path converges on; ANY live duplicate (tools, resources,
     templates, prompts) throws `Exception\DuplicateCapabilityException` at
     build time. Re-register after an explicit unregister stays allowed.
     Side effect: the registry is always eagerly loaded — intended.
  2. The reserved-names handshake gives BETTER error messages earlier:
     `McpServerFactory` collects the attribute tools' names (mirroring the
     SDK's own rule: the attribute's `name`, else the method name, else the
     class short name for `__invoke`) and hands them to every configurator
     implementing `ReservedToolNamesAwareInterface` before `configure()`;
     `OpenApiServerConfigurator` seeds its `$usedNames` map with them so a
     `tool_names`/modifier rename onto a taken name fails fast. If the SDK's
     naming rule drifts on a pin bump,
     `reservedNamesFollowTheSdkDefaultNamingRule` is the test that catches it.
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
- **MCP Apps: `_meta.ui` means two different things at two levels, and the
  wrong one is silently ignored.** The resource DESCRIPTOR (`resources/list`)
  carries only the bare marker (`McpApps::resourceMarker()`, an empty
  `\stdClass`) — that is what flags the resource as an app. The resource
  CONTENT (`resources/read`) carries `UiResourceContentMeta` (CSP,
  permissions, domain, prefersBorder) — the sandbox contract. A policy placed
  on the descriptor does nothing; a marker on the content tells the host
  nothing. `AppResourceHandler` therefore returns a `TextResourceContents`,
  not a string and not a `ReadResourceResult`: the SDK's resource formatter
  only carries `_meta` through on a ready `ResourceContents`, and it has no
  branch for `ReadResourceResult` at all (it throws on the unhandled type).
  `McpAppsConfigurator` is also the SINGLE enabler of the extension —
  `Builder::enableExtension()` throws `LogicException` on a duplicate id, so
  an application configurator that also enables `McpApps` while
  `apps.enable` is on fails the build. That is the intended fail-fast.
- **`Apps\AppParamParser` must never call the SDK's `UiResourceCsp::fromArray()`
  or `UiResourcePermissions::fromArray()`.** Both would misread this package's
  params format SILENTLY: permissions treat a PRESENT key as requested
  (`isset()`), so `'camera' => false` would switch the camera ON, and CSP
  reads camelCase (`connectDomains`) while params are snake_case
  (`connect_domains`) like every other option here — every field would come
  out `null`, no CSP would be emitted, and the operator would believe a domain
  was allow-listed while the host applied its own restrictive default. Map
  explicitly into the constructors; `tests/Apps/AppParamParserTest.php` is the
  regression guard. CSP domains themselves are passed through verbatim — the
  host enforces the policy and `definitions` is application-owned config, not
  client input.
- **`mcp/sdk` is pinned `~0.8.1` (tilde, not caret).** There is no separately
  maintained 3.x line. The SDK is experimental
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
- **Session store default must be FPM-safe AND owner-only.** MCP Streamable
  HTTP sessions span requests (`initialize` → `Mcp-Session-Id` → subsequent
  calls); the SDK's `InMemorySessionStore` default silently breaks under
  PHP-FPM, and its `FileSessionStore` creates a `0775` directory with
  umask-mode files — readable by other OS users. `config/di.php` binds
  `Session\PrivateFileSessionStore` (0700 dir, 0600 files) with an
  application-specific default directory (`Session\SessionDirectory`,
  derived from `server_name`); do not "simplify" either away.
- **Sessions are bound to the client that created them; the binding is
  immutable and enforced in TWO places — keep both.** The SDK only checks
  that a presented session UUID exists, and the UUID leaks into proxy/client
  logs via the HTTP header. (1) `McpAction` stamps
  `InterceptingReferenceHandler::CLIENT_ID_SESSION_KEY` as the owner right
  AFTER the initialize response (never first-call binding — whoever replays
  a fresh session id first would claim it) and answers the SDK's own 404
  shape to a POST/DELETE presenting a foreign or ownerless session when a
  client id is resolved. (2) `InterceptingReferenceHandler::clientId()`
  reads identity FROM THE SESSION first and throws
  `Exception\SessionOwnershipException` on an owner/holder mismatch — this
  runs BEFORE visibility, so a foreign session is never consulted for what
  the caller may see, and it keeps identity correct under Fiber-interleaved
  runtimes where the process-local holder cannot be trusted.
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
  `finally`. It is deliberately NOT the primary identity source — the
  session's immutable owner is (it travels with the request and survives
  Fiber interleaving); the holder covers only sessionless calls and
  sessions with no recorded owner, and a holder/owner disagreement fails
  closed. Do not read it outside `InterceptingReferenceHandler`, and never
  store the raw secret in it.
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
- Keep `resources/skills/rasuvaeff-yii3-mcp/SKILL.md` current in the same
  commit: it ships to consumers' agents (`llm/skills`) and states the safety
  rules they follow — a stale rule there is followed, not ignored.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
