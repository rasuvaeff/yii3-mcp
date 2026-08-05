---
title: "Multi-tenant serving"
description: "Serving a multi-tenant MCP endpoint with Yii3: middleware order, per-tenant session isolation, and tool visibility scoped by tenant."
---

# Multi-tenant serving

With [rasuvaeff/yii3-tenancy](https://github.com/rasuvaeff/yii3-tenancy) the
MCP endpoint can serve every tenant from one route — tools are ordinary Yii3
services, so a constructor-injected `CurrentTenant` scopes their data access
the same way as anywhere else in the application. The recipe is middleware
order: resolve the tenant **before** the MCP action runs.

## Middleware order

```php
// config/routes.php — secret first (fail-closed), then tenant, then MCP
Route::methods(['POST', 'GET', 'DELETE', 'OPTIONS'], '/mcp')
    ->middleware(SharedSecretMiddleware::class)
    ->middleware(TenantResolutionMiddleware::class)   // e.g. HeaderTenantResolver('X-Tenant-Id')
    ->action(McpAction::class),
```

```json
// an MCP client carries both headers
"headers": { "X-Mcp-Secret": "...", "X-Tenant-Id": "acme" }
```

`SharedSecretMiddleware` still runs first — fail-closed identity before
anything tenant-specific.

## Isolate sessions per tenant

Bind the session store to a per-tenant directory, so a session id can never
cross tenants:

```php
// config/common/di/mcp.php
SessionStoreInterface::class => static fn (CurrentTenant $tenant) =>
    new FileSessionStore(
        directory: sys_get_temp_dir() . '/mcp-sessions/' . $tenant->get()->getId(),
    ),
```

## Per-tenant tool sets

Come free with [`tool_visibility`](/visibility#per-session-visibility):
decide from the resolved tenant instead of `client_info`.

## Honest scope

The shared secret stays **global** — anyone holding it may present any
`X-Tenant-Id`. That fits the trusted-only endpoint model described in
[Security](/security): the secret already grants application access, so
tenant isolation here protects against **accidents**, not against a
malicious secret holder. Per-tenant secrets (a secret resolver keyed by
tenant instead of the single-value middleware) are a planned extension —
see the [roadmap](/roadmap).
