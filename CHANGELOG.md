# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 2.0.0 — 2026-07-27

Security-hardening pass over the 2026-07-26 external review (REVIEW-2026-07-26).
Several changes are **breaking** — see `UPGRADE.md`.

- **[breaking, security] Sessions are bound to the client that created
  them.** The SDK only checks that a presented `Mcp-Session-Id` exists, so
  any authenticated client could act inside — or DELETE — another client's
  session by replaying its id (verified end-to-end). `McpAction` now stamps
  the resolved client id into the session as an immutable owner at
  `initialize` and verifies it on every POST/DELETE before the transport
  runs; a foreign or ownerless session gets the SDK's own 404 shape,
  indistinguishable from an expired one. `InterceptingReferenceHandler`
  enforces the same binding as defence in depth (before visibility) and
  throws the new `Exception\SessionOwnershipException` on a mismatch.
  Sessions created before the upgrade have no owner and are rejected for
  authenticated clients — clients simply re-initialize.
- **[security] Client identity now travels with the session, not through
  process state.** The session's immutable owner is the primary identity
  source for capability calls; the process-local `ClientIdentityContext` is
  only a fallback for sessions without a recorded owner. This keeps
  attribution correct in concurrent/Fiber-interleaving runtimes, where the
  static slot could observe another request's id.
- **[breaking, security] `CachingToolCallInterceptor` requires a cache
  namespace and uses typed key material.** Two applications sharing one
  cache backend (a common Redis) with same-named tools could read each
  other's results; a real client literally named `anonymous` shared a
  partition with identity-less (stdio) callers. The key is now derived from
  a versioned, typed JSON structure (namespace, client id as `null|string`,
  tool, identity, canonicalized arguments). The namespace comes from the
  new `cache.namespace` param, defaulting to `server_name`. All previously
  cached entries miss once after the upgrade (by design — the format
  version is part of the key).
- **[breaking] `ToolCallLimiterInterface::allow()` takes `?string
  $clientId`.** Absence of identity is typed, never spelled as a reserved
  string; `RateLimitInterceptor` lost its `$fallbackClientId` parameter and
  passes `null` through — how anonymous calls are bucketed is the
  application limiter's decision.
- **[security] `StaticSecretResolver` rejects a secret shared by two client
  ids** (and a duplicate within one client's list): resolution returns the
  first match, so a shared secret silently attributed one client's calls —
  audit, rate limits, cache partitions, session ownership — to the other.
- **[security] Capability-name collisions fail the build on every
  registration path.** `McpServerFactory` now always installs a
  duplicate-guarding registry decorator: a tool/resource/template/prompt
  registered twice (attribute classes, configurators, the OpenAPI bridge,
  Markdown prompts — any combination) throws the new
  `Exception\DuplicateCapabilityException` instead of the SDK's silent
  last-write-wins that left name-keyed rules (visibility, cache, RBAC,
  audit) describing a vanished handler. The reserved-names handshake stays
  for its earlier, more specific error messages.
- **[breaking, security] The default session store is owner-only and
  application-specific.** The SDK's `FileSessionStore` creates a `0775`
  directory and umask-mode (`0644`) files — another OS user could read
  session JSON (client metadata, replayable session state). The new
  `Session\PrivateFileSessionStore` creates the directory `0700` (chmod
  after mkdir, immune to the umask) and clamps session files to `0600`;
  the default directory is now derived from `server_name`
  (`yii3-mcp-sessions-<slug>-<hash>`), so two applications on one host no
  longer share it. `mcp:doctor` fails the session-directory check when the
  directory is accessible to group/others, not only when it is unwritable.
- **[security] OpenAPI upstream responses are bounded before allocation.**
  `HttpOperationExecutor` used to materialize the whole body
  (`(string) $body` + `json_decode`) before any size check could run. It now
  reads incrementally and fails the call the moment the body crosses the new
  `openapi.max_response_bytes` cap (default 4 MiB); an advertised size over
  the cap is rejected without reading a byte, JSON decoding is depth-capped,
  and the error-path read is capped at excerpt size.
- **[security] Prompt substitution is budgeted before it happens.** One
  `prompts/get` argument is inserted at every occurrence of its placeholder
  (N-fold amplification). The expanded size is now computed arithmetically
  and checked against the new `limits.prompt_result_bytes` param (default
  1 MiB, 0 = unlimited) before the substituted string is built.
- **[breaking, security] Spec-fetch and operation-call credentials are
  separate scopes.** `openapi.headers` used to be sent to the spec host
  too — with `spec_path` and `base_url` on different origins, the API token
  went to the spec server. `headers` now authenticates operation calls
  only; the spec fetch uses the new `openapi.spec_headers` (empty by
  default). `SpecLoader` additionally rejects a spec URL embedding userinfo
  credentials, and `mcp:doctor` redacts userinfo from any URL it prints.
- **[security] The OpenAPI document itself is resource-bounded.** Size cap
  (10 MiB) for URL and file sources (the URL fetch reads incrementally),
  and `$ref` inlining now runs under an explicit node budget on top of the
  existing depth limit — a hostile or degenerate remote spec cannot make
  indexing recurse or allocate without bound.
- **[new] `openapi.opaque_errors`** suppresses the upstream error-body
  excerpt in bridged failures for service-token deployments where the
  upstream's error details are not the MCP caller's to see.
- `SpecIndex` decomposed into focused collaborators (`JsonPointerResolver`,
  `OutputSchemaProjector`, `OperationContractValidator`, all `@internal`) so
  resource limits and trust decisions each live in one place. No behavior
  change beyond the new budgets above.
- The package's only Psalm suppression is gone: `stubs/Tool.phpstub` corrects
  the SDK's inaccurate `ToolInputSchema` docblock instead of suppressing
  `ArgumentTypeCoercion`.
- `Prompts\PromptFile` restores its error handler in a `finally`;
  `Testing\SchemaSnapshot` writes via temp-file + atomic rename (a crash can
  no longer leave a truncated snapshot); `mcp:list` states that it shows the
  default (unauthenticated) capability view; `mcp:doctor` truncates and
  redacts exception details and no longer promises the impossible about
  third-party exception messages; `ResponseSizeLimitInterceptor` documents
  its raw-bytes-vs-wire-bytes semantics.
- A bridged tool whose name collides with an attribute tool's now fails the
  server build instead of vanishing. The SDK's registry is last-write-wins
  and its loaders register explicit tools (the OpenAPI bridge) *before*
  reflected ones (`#[McpTool]`), so the attribute tool won and the bridged
  tool silently never appeared in `tools/list` — easy to hit now that
  `tool_names` and `OperationModifierInterface` can rename anything.
  `McpServerFactory` reserves the attribute tools' names and passes them to
  any configurator implementing the new
  `ReservedToolNamesAwareInterface` (implemented by
  `OpenApiServerConfigurator`) before `configure()` runs.
- Truncation no longer splits a multi-byte character. Both places that cut a
  string short — `ResponseSizeLimitInterceptor` (`limits.tool_result_bytes`)
  and the upstream error-body excerpt in the OpenAPI bridge — used a byte-wise
  `substr`, so any non-ASCII payload could end in a half-written UTF-8
  sequence. The SDK encodes the JSON-RPC response with `JSON_THROW_ON_ERROR`
  and drops an unencodable one silently on the Streamable HTTP transport, so a
  successful tool call could simply never reach the client. The size limit
  stays a byte budget: a character that does not fit whole is dropped and the
  truncation marker now reports the bytes actually kept.
- OpenAPI bridge: an upstream error body that is not valid UTF-8 (a legacy
  encoded error page, a binary payload) is reported as
  `<non-UTF-8 response body, N bytes>` instead of being embedded verbatim —
  it would have made the tool-error envelope unencodable regardless of length.
- `CachingToolCallInterceptor`: the cache key now also includes the resolved
  `ExecutionIdentity` when `openapi.identity_provider` is configured —
  delegated upstream credentials can make results identity-specific below
  the client-id level, and a key partitioned by client id alone could serve
  one end user's upstream response to another. An identity provider failure
  fails closed for cached tools (a cache outage still fails open). The key
  is also shortened to PSR-16's guaranteed 64 characters (truncated sha256)
  so strict cache implementations no longer silently disable caching;
  previously written entries are orphaned until their TTL expires.
- `OpenApi\SpecLoader`: its own cache key (for the fetched OpenAPI document,
  distinct from `CachingToolCallInterceptor`'s tool-result cache above) is
  now also shortened to PSR-16's guaranteed 64 characters — it was missed
  when the tool-result cache key was fixed and would silently disable
  document caching on a strict PSR-16 implementation.
- OpenAPI bridge: a dry-run-enabled operation now rejects a
  present-but-non-boolean `dryRun` value with an error instead of executing
  the real call — a malformed preview intent must never become a real write.
- OpenAPI bridge: a path argument is rejected at call time when it is empty
  or `.`, or when it *contains* `..`, `/` or `\`. `rawurlencode` keeps dots
  verbatim and encodes `/` as `%2F`, which upstreams that decode before
  normalizing the path hand back as a real separator, so comparing the value
  against `.`/`..` for equality was narrower than the escape it was meant to
  prevent — `../..` and `x/..` passed it. An empty value turns an
  allow-listed item route into the collection route. Trade-off: an
  identifier containing `..` can no longer be used as a path argument;
  single dots (`v1.2`) still can.
- OpenAPI bridge: an operation whose *static* path (the OpenAPI Path Item
  Object key itself, not a `{param}` argument) does not start with `/` is now
  skipped when the spec is indexed, instead of being bridged. Without the
  leading slash, `HttpOperationExecutor` (which builds the request URL as
  `baseUrl . path` with no separator) would splice the path into the host —
  `https://api.test` + `evil.com/x` became `https://api.testevil.com/x`, a
  different host, contacted with this bridge's delegated credentials. The
  OpenAPI spec itself requires the leading slash, so this only rejects a
  non-conformant document.
- OpenAPI bridge: an `outputSchema`'s `additionalProperties` with an empty
  object schema (`{}`) is now omitted instead of being emitted as `[]` on the
  wire — the same "empty map serializes as an array" hazard already fixed for
  `properties`. JSON Schema requires `additionalProperties` to be a boolean or
  a schema object; a JSON array made schema-validating MCP clients reject the
  whole `tools/list`.
- OpenAPI bridge: the dry-run preview mirrors the real-send condition for
  `body` — a stray `body` argument on a bodyless operation is no longer
  shown as if it would be sent.
- `Interceptor\ArgumentMasker`: the default sensitive-key list now also
  covers the OAuth and key spellings its exact-match comparison would have
  let through — `access_token`/`accessToken`, `refresh_token`/`refreshToken`,
  `client_secret`/`clientSecret`, `private_key`/`privateKey` and
  `authorization`. Audit/telemetry bridges will mask more arguments than
  before.
- The OpenAPI `identity_provider` is no longer resolved from the container on
  servers that configure neither the bridge nor the tool cache.
- Documented two trust boundaries that were implicit: a `tag:` visibility
  rule is only as trustworthy as the OpenAPI document it reads tags from (a
  URL spec that drops a tag disarms a `tag:` deny rule), and the tool cache
  key identifies the MCP client, not the application user behind it — a tool
  whose result depends on the logged-in user needs
  `openapi.identity_provider` before it is safe to cache.
- `Interceptor\ArgumentMasker`: the default sensitive-key list now also
  covers the common API-key spellings `apikey` (matches `ApiKey`
  case-insensitively), `api-key` and `x-api-key`.
- `Interceptor\ArgumentMasker`: the default sensitive-key list now also
  covers `pass`, `pwd`, `auth`, `bearer`, `jwt`, `cookie`, `id_token`,
  `session_token`, `auth_token` and the kebab spelling `access-token` —
  industry-standard credential/token names the previous list missed.
- OpenAPI bridge: `HttpOperationExecutor` rejects a base URL with embedded
  credentials (userinfo) or a query string/fragment at construction —
  dry-run previews return the full URL to the caller, so the base URL must
  never carry credentials.
- Add `openapi.dry_run` (`dryRunOperations` on `OpenApiServerConfigurator`):
  an operationId in the list gets an extra `dryRun` boolean input argument.
  Calling with `dryRun: true` returns the request that would be sent
  (`operationId`, `method`, `url`, `body`) as text, without sending it and
  without upstream headers leaving the process. Fail-closed by construction:
  the check is a second boolean threaded from the handler into the executor,
  so a `dryRun` argument on a non-enabled operation is always ignored.
  Orthogonal to `safe_methods_only` — does not expose an operation the
  safety gate would otherwise reject.
- Bump `mcp/sdk` to `~0.7.0` (from `~0.6.0`). Verified empirically (full
  build + mutation + bc-check), not just from the changelog: every SDK class
  this package depends on (`Tool`, `ToolAnnotations`, `Registry`,
  `NameValidator`) is unchanged between the two versions.
- Add `Interceptor\ResponseSizeLimitInterceptor` (`limits.tool_result_bytes`
  param): guards against a tool result burning an agent's context window.
  A string result over the limit is truncated with a marker; any other
  result (array, object) is rejected instead.
- Add `Interceptor\CachingToolCallInterceptor` (`cache.tools` param, PSR-16):
  caches successful tool results per client, opt-in by tool name with a
  TTL. The cache key always includes the resolved client id.
- OpenAPI bridge: advertise every `GET` operation with `readOnlyHint: true`,
  and propagate OpenAPI `tags` into the served tool's `_meta`
  (`{"rasuvaeff/yii3-mcp": {"tags": [...]}}`).
- Add a `tag:` prefix to `Visibility\DeclarativeToolVisibility` patterns,
  matching a tool's tags (from `_meta`) instead of its name — a tool with no
  tags never matches a `tag:` pattern.
- Add `OpenApi\OperationModifierInterface` (`openapi.operation_modifier`
  param): a per-operation customization hook, applied after the
  `tool_names` rename, for changing a bridged tool's description,
  annotations, or name without writing a full
  `ServerConfiguratorInterface`. `OpenApi\Operation` is now `@api` to serve
  as its read-only context.
- Add `openapi.tool_names` (`toolNames` on `OpenApiServerConfigurator`): rename
  an operationId into an LLM-friendly MCP tool name. The allow-list, handler
  execution and delegated-header calls stay keyed by operationId — only the
  served tool name changes.
- Reject an `operationId` that cannot serve as an MCP tool name
  (`^[A-Za-z0-9._/-]{1,64}$`) at build time with `InvalidSpecException`,
  instead of relying on `mcp/sdk`'s registration-time warning (which does
  not stop the tool from being registered). The same check applies to a
  `tool_names` rename.
- Treat a `null` argument for an OpenAPI-bridged path/query parameter as
  omitted (skipped) rather than stringified.
- Accept OpenAPI 3.1's nullable union type notation
  (`{"type": ["string", "null"]}`) on scalar parameter schemas, and
  (`{"type": ["object", "null"]}`) on the response schema advertised as
  `outputSchema`, alongside the plain 3.0 type strings.
- Known limitations reviewed and accepted, not fixed (each is a narrow edge
  case, a delegated concern, or a documented trade-off):
  - `HttpOperationExecutor::errorExcerpt()`'s UTF-8 placeholder can be
    bypassed only by an upstream error body over 2000 bytes composed
    entirely of stray continuation bytes (`0x80`-`0xBF`) — `Utf8::cut`
    backs that off to an empty string, which trivially passes the
    validity check. A real legacy-encoded body (Latin-1, CP1251) has lead
    bytes that stop the back-off well before this, so the placeholder
    fires correctly for the cases the feature actually targets.
  - Delegated/default headers are not validated for embedded CRLF; a
    conforming PSR-7 implementation (Guzzle, Nyholm) already rejects a
    CRLF-containing header value at `withHeader()` time, so this is the
    PSR-7 layer's job, not this package's.
  - `Identity\StaticSecretResolver`'s early-exit-on-first-match lookup
    reveals which configured client matched through the number of
    `hash_equals` calls made (each call itself stays constant-time). Not
    exploitable in this package's threat model — the client list is
    admin configuration, not attacker-visible.
  - `mcp/sdk`'s `DnsRebindingProtectionMiddleware::isAllowedHost()` does
    not strip a trailing dot from an FQDN (`app.example.com.` is rejected
    even when `app.example.com` is allow-listed); this lives in `mcp/sdk`,
    not in this package's code.
  - `InterceptingReferenceHandler`'s session-mirrored client id attribution
    can survive a secret's revocation from `client_secrets` until the
    session's own TTL expires — the mirror is a convenience for
    audit/telemetry, not a live-revocation check; revoking access
    immediately requires also invalidating the affected sessions.
  - Two concurrent cold-miss calls for the same cache key both execute the
    tool and both write; the second write wins. A performance
    inefficiency (one extra tool execution), not a correctness or security
    issue — the cache stays partitioned per client/identity regardless.

## 1.8.0 — 2026-07-25

- Add conditional PSR-17/18 and PSR-16 service diagnostics plus expected HTTP
  host validation to `mcp:doctor`.
- Add optional scoped PSR-16 caching for URL OpenAPI documents, including
  corrupt-cache and unavailable-cache fallback.
- Add per-call execution identity and delegated-header provider contracts for
  upstream credentials; static service-token mode now reports its trust model.
- Add a verified end-to-end MCP integration guide.

## 1.7.2 — 2026-07-25

- Reject trailing newlines in `DeclarativeToolVisibility` pattern matching:
  anchor the compiled glob-regex with `\z` instead of `$` (PCRE `$` matches
  before a trailing `\n`, which let a tool name ending in `\n` slip past an
  exact-match deny/allow pattern).

## 1.7.1 — 2026-07-25

- Fix: an OpenAPI operation without parameters and without a request body now
  serves `"properties": {}` instead of `"properties": []` in its `inputSchema`.
  `mcp/sdk` normalizes empty properties only inside `Tool::fromArray()`, which
  the bridge does not use, so clients validating the schema as a record
  (`expected record, received array`) rejected the whole `tools/list`.
- Fix: an object-typed success response declaring no properties no longer
  advertises an empty `properties` map in `outputSchema` — the key is optional
  and an empty array would serialize the same broken way.

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
