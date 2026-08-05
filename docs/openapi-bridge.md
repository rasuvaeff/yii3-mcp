---
title: "OpenAPI bridge"
---

# OpenAPI bridge

If the application already maintains an OpenAPI 3.0.x or 3.1.x document,
allow-listed operations can be bridged as MCP tools with zero duplication:
names come from `operationId` (or `tool_names`), descriptions from
`summary`/`description`, input schemas from parameters/request body, output
schemas from the success response. Calls execute as real HTTP requests
against the API, passing its full middleware stack (validation, rate
limiting, auth) — unlike a hand-written tool that invokes a handler
directly.

```php
'rasuvaeff/yii3-mcp' => [
    'openapi' => [
        // file path OR http(s) URL — e.g. the app's own spec endpoint,
        // always current; fetched with spec_headers, NOT with headers
        'spec_path' => 'https://api.example.com/rest/json-url',
        'base_url' => 'https://api.example.com',
        'operations' => ['getBlogTags', 'getPage'],   // allow-list; empty = nothing
        'tool_names' => ['getBlogTags' => 'blog_tags_list'],
        'headers' => ['Authorization' => 'Bearer ' . getenv('MCP_API_TOKEN')],
        'spec_headers' => [],
        'cache_ttl' => 60,               // PSR-16 URL-spec cache; 0 = fetch every build
        'safe_methods_only' => true,     // read-only bridge: non-GET in the list => build error
        'max_response_bytes' => 4_194_304,
        'opaque_errors' => false,
    ],
],
```

Disabled while `spec_path` is empty; an empty `operations` allow-list
exposes nothing even with a spec configured.

## Credential scopes are separate on purpose

`headers` authenticates operation calls against `base_url`; `spec_headers`
authenticates the spec **fetch** against `spec_path`. When the two live on
different origins, a shared header set would hand the API token to the spec
host. A spec URL embedding credentials (userinfo) is rejected outright — it
would otherwise end up in diagnostics and exception messages.

**Bridged operations execute with the configured upstream credentials — the
upstream API does not automatically inherit the MCP caller's identity or
RBAC decision.** Do not expose user/tenant-scoped operations with a broader
service token than the caller should have.

## Renaming: `tool_names`

`tool_names` only renames what MCP clients see — the allow-list, handler
execution, and delegated-header calls all stay keyed by `operationId`.
[Interceptors](/interceptors), [visibility](/visibility) rules, and any
audit/RBAC bridge must reference the **renamed** name. An `operationId` in
`tool_names` absent from `operations` throws `InvalidArgumentException` at
build time (a likely typo); a rename that is invalid as an MCP tool name or
collides with another tool's name throws `InvalidSpecException`. The
collision check covers attribute tools too — see
[Security: capability name collisions](/security#capability-name-collisions).

Every `GET` operation is advertised with `readOnlyHint: true` automatically.
OpenAPI `tags` propagate into the served tool's `_meta`
(`{"rasuvaeff/yii3-mcp": {"tags": [...]}}`), which the declarative `tag:`
[visibility](/visibility#declarative-tool-visibility) pattern reads
directly.

## Output schema from responses

A bridged tool advertises `outputSchema` when the operation declares a
matching success response: the **lowest concrete 2xx** response with an
`application/json` schema of `type: object` (OpenAPI 3.1's `type: ["object",
"null"]` nullable union is accepted the same way; local `$ref`s resolved;
top-level keywords canonicalized to `type`/`properties`/`required`/
`additionalProperties`/`description`). Array/scalar responses and `2XX`
wildcards are not advertised — a JSON object payload still arrives as
`structuredContent`, just without the upfront contract. Keep the OpenAPI
document honest: a spec that diverges from the API surfaces as client-side
validation errors.

## Per-operation customization

`OperationModifierInterface` is a per-operation hook, applied **after** the
`tool_names` rename — for changing a description, adding annotations, or
renaming further without writing a whole `ServerConfiguratorInterface`.
A further rename it produces is validated and checked for collisions the
same way as a `tool_names` rename.

## Delegated authorization

For delegated authorization, configure **both** `identity_provider` and
`delegated_header_provider`:

- the identity provider returns an immutable `ExecutionIdentity`
  (`subjectId`, `tenantId`, `clientId`);
- the header provider is called on **every** operation call and receives
  only the operation id/method/path plus that identity — never the raw MCP
  shared secret — and exchanges it for headers.

Do not forward the inbound `Authorization` header verbatim. A provider
failure stops the call **before** HTTP (fail-closed). Dynamic headers
override matching static ones, without cross-call reuse. This is also what
partitions the [tool-result cache](/interceptors#result-size-limit-and-caching)
by identity, below the client-id level.

## Dry run

Operations listed in `dry_run` get an extra `dryRun` boolean input argument;
a call with `dryRun: true` returns the planned request (method, url, body)
as a plain string instead of executing it — never as `structuredContent`,
so it can never contradict the operation's declared `outputSchema`, and
never including headers, since those may carry server-side credentials the
caller never supplied. Orthogonal to `safe_methods_only` — it does not
expose an operation the safety gate would otherwise reject, and a client
cannot smuggle `dryRun: true` into a non-`dry_run`-enabled operation to get
a preview instead of a real call.

## Path arguments are validated, not just encoded

A path argument is rejected at call time when it is empty, `.`, or contains
`..`, `/`, or `\`. `rawurlencode()` keeps dots verbatim and encodes `/` as
`%2F`, which upstreams that decode before normalizing the path (Apache with
`AllowEncodedSlashes`, some proxies and servlet containers) hand back as a
real separator — so a value like `../..` could climb out of the
allow-listed route using the bridge's credentials; an empty value is the
same escape one level up (`/users/` is typically the collection route, not
the allow-listed item route). Single dots are fine (`v1.2` is a valid
slug) — a value that genuinely needs a slash or `..` cannot be bridged as a
path argument.

The **base URL** must not embed credentials (userinfo) or carry a query
string/fragment — dry-run previews return the full URL to the caller, so
the base URL can never be a credential carrier.

## Resource bounds, enforced before allocation

- The upstream response body is read **incrementally**; the call fails the
  moment it crosses `max_response_bytes` — an advertised `Content-Length`
  already over the cap is rejected without reading at all.
- JSON decoding is depth-capped.
- The OpenAPI document itself is size-bounded (10 MiB) for URL and file
  sources alike, and `$ref` inlining runs under an explicit depth + node
  budget — a hostile or degenerate remote spec cannot make indexing recurse
  or allocate without bound.
- Upstream error bodies are excerpted (bounded, UTF-8-safe) into the tool
  error, or suppressed entirely with `opaque_errors` when upstream error
  details are not the MCP caller's to see.

## Spec parsing constraints

Local `#/components/...` `$ref`s resolve inline (up to 32 chained hops);
external (URL/file) `$ref`s pass through unresolved for request-body
schemas. URL parameters are limited to scalar `string`/`integer`/`number`/
`boolean` schemas with OpenAPI's defaults (`simple` path, `form` query) —
header/cookie parameters, external or non-scalar parameter schemas, custom
serialization, non-default `explode`, and `allowReserved=true` throw
`InvalidSpecException` when the operation is selected. Duplicate
`operationId` values fail while indexing. An operation with a path and a
query parameter sharing one name — or a parameter named `body` alongside a
request body — cannot be bridged. An `operationId` unusable as an MCP tool
name (space, unicode, over 64 characters —
`^[A-Za-z0-9._/-]{1,64}$`) throws `InvalidSpecException` when selected,
rather than surfacing only as an opaque `tools/list` rejection on the
client. A `null` path/query argument is treated as omitted, matching
OpenAPI 3.1's nullable union notation on scalar parameter schemas.

## Caching the URL spec

A URL spec can be cached via PSR-16 (`cache_ttl`); the cache stores the raw
document, and allow-listing/validation run on **every** server build
regardless. A cache failure falls back to HTTP; an HTTP or spec failure
remains fail-closed. A removed operation can remain callable for up to the
TTL — use a local file or a short TTL for security-sensitive specs.

For custom scenarios, use the pieces directly: `SpecIndex` +
`HttpOperationExecutor` + `OpenApiServerConfigurator` — a
`ServerConfiguratorInterface`, the generic extension point accepted by
`McpServerFactory::create(tools, configurators)`.

See [Cookbook: bridging an existing REST API](/cookbook/bridging-existing-api)
for a worked example.
