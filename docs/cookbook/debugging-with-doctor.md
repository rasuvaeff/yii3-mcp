---
title: "Cookbook: debugging with mcp:doctor"
---

# Cookbook: debugging with mcp:doctor

`mcp:doctor` is the first thing to reach for when an MCP deployment
misbehaves — before opening a debugger, before adding a var_dump. It checks
the server configuration end-to-end and reports each check as **pass** /
**skip** / **fail**. See [Operations](/operations#diagnostics-mcp-doctor)
for the full check list and category model.

## "The client gets a 503"

```bash
./yii mcp:doctor
```

A 503 from `SharedSecretMiddleware` means the endpoint has no secret
configured at all (see [Security](/security#shared-secret)). `mcp:doctor`'s
first config check surfaces this directly rather than making you read the
middleware source — read the failing check's message, it names the exact
param to set (`endpoint_secret` / `client_secrets`).

## "mcp:list works but the client can't connect"

`mcp:list` exercises the **console** DI group; the HTTP endpoint runs in
the **web** group. A service bound in one but not the other is invisible to
`mcp:list` and only breaks at request time. Run `mcp:doctor` against the
same environment the client actually hits — it reports every missing PSR
service by its exact interface name (`ResponseFactoryInterface`,
`ServerRequestFactoryInterface`, …), so a `di.php` split between config
groups is easy to spot. See
[Architecture: PSR services](/intro/architecture#what-s-a-psr-service-vs-what-s-package-config)
for which entry point needs what.

## "Sessions keep disappearing" or "confidentiality" failures

`mcp:doctor`'s storage checks include session directory **confidentiality**
— a directory readable by group or others fails the check even when it's
perfectly writable. This is deliberate: session JSON carries client
metadata and everything needed to replay a session id (see
[Security: sessions on disk](/security#sessions-on-disk)). Fix the
directory's permissions (or let the default `PrivateFileSessionStore`
create it) rather than relaxing the check.

## "The OpenAPI bridge doesn't build"

```bash
./yii mcp:doctor --probe
```

Without `--probe`, `mcp:doctor` never touches the network — a URL
`spec_path`'s fetch and the server build (which loads the spec eagerly) are
both reported as **skipped**, not passed. `--probe` actually fetches the
spec, which is what you want when the failure is upstream (a 404, a
malformed document, an unreachable host) rather than in local config. See
[OpenAPI bridge](/openapi-bridge) for the fail-closed validation rules a
build-time failure might be enforcing.

## Reading exit codes in CI

```bash
./yii mcp:doctor --json
echo "exit: $?"
```

Exit codes are stable for scripting: `0` healthy, `2` config error, `3`
storage error, `4` upstream error — the category of the **first** failing
check (checks run root-causes-first). Gate a deploy on this exit code
directly rather than grepping the human-readable table, and remember the
JSON output — like the table — never contains the configured secret or
header values.

## When mcp:doctor is green but the real client still fails

Two things it deliberately does **not** simulate:

- **Client identity.** `mcp:doctor` and `mcp:list` both drive a synthetic,
  unauthenticated session — a real client's [visibility](/visibility) or
  [RBAC](/bridges/rbac) outcome can differ. See
  [Operations: mcp:list](/operations#introspection-mcp-list).
- **Session ownership across requests.** A single doctor run doesn't
  exercise `initialize` → subsequent-call binding — see
  [Security: session ownership](/security#session-ownership). Reproduce a
  session-binding issue with `McpTester` in a test instead (see
  [Cookbook: your first MCP server](/cookbook/mcp-server-first-time)), or
  with the MCP Inspector against the live endpoint.
