---
title: "Cookbook: bridging an existing REST API"
---

# Cookbook: bridging an existing REST API

If the application already maintains an OpenAPI document, exposing a handful
of read endpoints as MCP tools can be zero-duplication config — no
hand-written tool class at all. Full reference:
[OpenAPI bridge](/openapi-bridge).

## 1. Start read-only, from a local spec file

```php
'rasuvaeff/yii3-mcp' => [
    'openapi' => [
        'spec_path' => __DIR__ . '/../resources/openapi.json',
        'base_url' => 'https://api.example.com',
        'operations' => ['getBlogTags', 'getPage'],
        'headers' => ['Authorization' => 'Bearer ' . getenv('MCP_API_TOKEN')],
        'safe_methods_only' => true,   // build fails if a non-GET operation sneaks into the list
    ],
],
```

A local file avoids the two extra concerns a URL spec brings in (network
failure modes, TTL-bounded staleness — see
[OpenAPI bridge: caching the URL spec](/openapi-bridge#caching-the-url-spec)),
and `safe_methods_only` is a second line of defense while you're still
deciding what should be write-enabled.

## 2. Verify what actually got bridged

```bash
./yii mcp:list
```

Confirm `getBlogTags`/`getPage` show up with the arguments and output
schema you expect — [Operations: mcp:list](/operations#introspection-mcp-list)
goes through the same in-process path a real client uses, so a spec
indexing failure (bad `$ref`, unsupported parameter shape — see
[OpenAPI bridge: spec parsing constraints](/openapi-bridge#spec-parsing-constraints))
shows up here as a build error rather than silently.

## 3. Rename for the agent's benefit

Generated `operationId`s are rarely LLM-friendly:

```php
'openapi' => [
    // ...
    'tool_names' => ['getBlogTags' => 'blog_tags_list'],
],
```

From here on, every reference to this tool — [interceptors](/interceptors),
[visibility](/visibility) patterns, an audit/RBAC rule — must use the
**renamed** name, `blog_tags_list`, not `getBlogTags`. The allow-list and
the actual HTTP call still key off `operationId` internally; only what the
client sees changes.

## 4. Add a dry-run for anything write-shaped

Before enabling a mutating operation for real, preview what it would send:

```php
'openapi' => [
    // ...
    'operations' => ['getBlogTags', 'getPage', 'createComment'],
    'dry_run' => ['createComment'],
],
```

A call to the `createComment` tool with `dryRun: true` returns the planned
request instead of executing it. This is orthogonal to `safe_methods_only`
— it does not itself expose `createComment`, it only adds a preview mode
once the operation is in the allow-list. See
[OpenAPI bridge: dry run](/openapi-bridge#dry-run) for the fail-closed
guarantees around it.

## 5. Move from a service token to delegated identity, if needed

The setup so far calls the upstream API with **one** static credential
(`headers`) — every MCP client shares it, and the upstream sees no
per-user distinction. If the tools should act as the calling application
user rather than a shared service account:

```php
'openapi' => [
    // ...
    'identity_provider' => AppExecutionIdentityProvider::class,
    'delegated_header_provider' => AppDelegatedHeaderProvider::class,
],
```

Read [OpenAPI bridge: delegated authorization](/openapi-bridge#delegated-authorization)
before flipping this on — the header provider receives only the resolved
identity, never the raw MCP secret or the inbound `Authorization` header,
and a provider failure stops the call before any HTTP request is made.

## 6. Move the spec to a URL once it's stable

```php
'openapi' => [
    'spec_path' => 'https://api.example.com/rest/json-url',
    'spec_headers' => [],       // separate credential scope from `headers`
    'cache_ttl' => 60,
],
```

Now the bridge always reflects the live API surface — at the cost of the
staleness window `cache_ttl` introduces (a removed operation can remain
callable for up to the TTL) and a new network failure mode `mcp:doctor
--probe` can check ahead of time (see
[Cookbook: debugging with mcp:doctor](/cookbook/debugging-with-doctor)).
