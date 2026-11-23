# AGENTS.md — yii3-mcp

Guidance for AI agents working on this package. Read before changing code.

## What this is

Yii3 package over `rasuvaeff/mcp` (runtime, `Rasuvaeff\Mcp`) and
`rasuvaeff/mcp-openapi` (OpenAPI bridge, `Rasuvaeff\Mcp\OpenApi`). It owns the
Yii3 side only: `config/di.php` + `config/params.php` (`yiisoft/config` plugin),
the params interpreter `McpServerComponentResolver` (@internal) and
`McpDoctorCommand` with `Doctor\*`. Runtime and bridge rules are in the
`AGENTS.md` of those packages: change them there, not here.

Public API: `McpDoctorCommand`,
`Doctor\{McpDoctor, DoctorReport, CheckResult, CheckStatus, CheckCategory}`.

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

- **Local 6.0 work before `rasuvaeff/mcp` and `mcp-openapi` are published** uses
  `COMPOSER=composer.local.json` (untracked, path repositories).
- **A non-empty `prompts_path` fails the build** (Markdown prompts were removed
  in 6.0): ignoring it would drop prompts silently.
- **`mcp:serve`/`mcp:list`/`mcp:doctor` are registered through the package's
  `yiisoft/yii-console` params** — the contribution is a contract tested by
  `ConfigWiringTest::consoleCommandsAreRegisteredThroughYiiConsoleParams`, not
  prose. `yiisoft/yii-console` is deliberately NOT a dependency: the params key
  is inert in an application without the console component. Any new console
  command joins that same block; do not move registration into `di.php`.
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
