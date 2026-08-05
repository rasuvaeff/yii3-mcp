---
title: "Operations"
---

# Operations

Two console commands answer "what is actually being served" and "is the
configuration healthy" without needing a real MCP client.

## Introspection: `mcp:list`

```bash
./yii mcp:list
./yii mcp:list --json   # full definitions as normalized JSON
```

`mcp:list` prints every registered tool, resource, resource template, and
prompt, with argument summaries (`name*` = required). It goes through the
**same in-process JSON-RPC path** a real client uses, so attribute tools,
OpenAPI-bridged operations, and Markdown prompts all show up identically —
this is not a static reflection dump.

`--json` prints complete capability definitions (input/output schemas
included) in `Testing\SchemaSnapshot`'s normalized form: item order and
object keys are stable, so the output diffs cleanly in CI and feeds
external automation (commit it as a snapshot — see
[Cookbook](/cookbook/mcp-server-first-time)).

The listing is the **default, unauthenticated view** — the command drives a
synthetic session with no client identity, and says so in its output. With
per-session [visibility](/visibility) or the [RBAC bridge](/bridges/rbac)
wired in, a real client may see a different, narrower capability set than
what `mcp:list` prints.

`mcp:list` needs `ServerRequestFactoryInterface`, `ResponseFactoryInterface`,
and `StreamFactoryInterface` bound in the container — see
[Architecture](/intro/architecture#what-s-a-psr-service-vs-what-s-package-config).

## Diagnostics: `mcp:doctor`

```bash
./yii mcp:doctor           # human-readable table
./yii mcp:doctor --json    # machine-readable report
./yii mcp:doctor --probe   # also fetch a URL OpenAPI spec over the network
```

`mcp:doctor` checks the server configuration end-to-end and reports each
check as **pass** / **skip** / **fail**. Checks span three categories
(`Doctor\CheckCategory`): config, storage, upstream — and include:

- the endpoint secret and the optional `expected_http_host` allow-list,
- every PSR service required by enabled entry points/features (reported by
  exact interface name),
- session storage, including **confidentiality** — a session directory
  readable by group/others fails the check, not merely an unwritable one,
- the OpenAPI spec (network fetch only with `--probe`),
- the MCP Apps configuration — every declarative definition is parsed, so a
  malformed one is reported even when the server-build check itself is
  skipped,
- a real server build.

Exit codes are stable for scripting — `0` healthy, `2` config error, `3`
storage error, `4` upstream error — reflecting the category of the
**first** failing check. Checks run root-causes-first, so a broken config
reports as a config error even though it also breaks the server build.

**The output never contains the configured secret or header values**, and
printed URLs are stripped of userinfo credentials — exception messages from
application services do pass through (truncated), so treat the report as
operator-facing diagnostics, not something to paste into a public issue
verbatim.

Without `--probe` the command never touches the network: with a URL
`spec_path`, both the spec fetch and the server build (which loads the spec
eagerly) are reported as **skipped**, not failed.

See [Cookbook: debugging with mcp:doctor](/cookbook/debugging-with-doctor)
for a walkthrough of diagnosing a broken deployment.

## Interactive debugging

For manual exploration against a running Streamable HTTP endpoint, use the
official MCP Inspector:

```bash
npx @modelcontextprotocol/inspector
# transport: Streamable HTTP, URL: https://your-app/rest/mcp,
# header: X-Mcp-Secret: <secret>
```

## Contract drift detection

`Testing\SchemaSnapshot` snapshots every served capability definition
(everything `mcp:list --json` prints) into a committed JSON file; a test run
comparing against it fails on any drift — a changed method signature
silently changes the generated `inputSchema`, which would otherwise break
agents mid-flight without any test noticing. See
[Cookbook: your first MCP server](/cookbook/mcp-server-first-time).
