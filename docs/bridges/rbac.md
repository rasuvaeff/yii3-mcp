---
title: "RBAC bridge"
---

# RBAC bridge

`rasuvaeff/yii3-mcp-rbac-bridge` adds per-user authorization for MCP servers
over the Yii3 auth stack — the application-facing alternative to OAuth 2.1:
RBAC permissions enforced on every `tools/call`, permission-aware
`tools/list` filtering, and session-identity binding against session
hijacking.

## Install and wire

```bash
composer require rasuvaeff/yii3-mcp-rbac-bridge
```

## Two auth layers

`SharedSecretMiddleware` (core) stays — it is **machine auth**: may this MCP
client talk to this endpoint at all. This bridge adds **user auth**: what
may the authenticated user behind the call actually do. Both layers run;
adding RBAC does not remove the need for the shared secret.

```php
// config/routes.php — secret first (cheap fail-closed), then identity
Route::methods(['POST', 'GET', 'DELETE', 'OPTIONS'], '/mcp')
    ->middleware(SharedSecretMiddleware::class)
    ->middleware(Authentication::class)       // yiisoft/auth: token -> CurrentUser
    ->action(McpAction::class),
```

## 1. Declare permissions on tools

```php
use Rasuvaeff\Yii3McpRbacBridge\RequiredPermission;

final readonly class OrderTools
{
    #[McpTool(name: 'order.status')]
    #[RequiredPermission('orders.view')]
    public function status(string $orderId): string { /* … */ }

    #[McpTool(name: 'ping')]          // no attribute = unrestricted
    public function ping(): string { /* … */ }
}
```

Restriction is explicit and per-tool — tools without a permission stay open
(behind the shared secret). `#[RequiredPermission]` on a method without
`#[McpTool]` fails the build: a permission that would never be enforced is a
bug, not a default. Two attributes mapping one tool name to two different
permissions also fails the build — explicit overrides win by design, never
a silent last-one-wins.

### Tool names: what the map keys must be

`PermissionMap` keys are tool names, derived exactly as the core registers
them — so `tools/list` filtering and the `tools/call` check can never key
off different names:

| Tool declaration | Registered name = map key |
|---|---|
| `#[McpTool(name: 'order.status')]` | `order.status` — the explicit name wins |
| `#[McpTool]` on `public function status()` | `status` — the method name |
| `#[McpTool]` on `public function __invoke()` | the **class short name** (e.g. `RefundTool`), not `__invoke` |

`PermissionMap::fromToolClasses()` computes these keys automatically. Only
an **explicit** override array is yours to key correctly:

```php
new PermissionMap(['RefundTool' => 'orders.refund']);   // invokable RefundTool::__invoke
```

A key matching no registered tool is inert — the tool stays unrestricted, so
keep explicit keys in sync with the table above.

## 2. Wire the bridge

```php
// config/common/di/mcp-rbac.php
use Rasuvaeff\Yii3McpRbacBridge\{CurrentUserIdentitySource, IdentitySourceInterface, PermissionMap, StaticIdentitySource};

return [
    IdentitySourceInterface::class => CurrentUserIdentitySource::class,
    PermissionMap::class => static fn () => PermissionMap::fromToolClasses(
        [OrderTools::class],                       // same list as the `tools` params
        // ['order.status' => 'orders.admin'],     // optional explicit overrides
    ),
];
```

Split identity wiring by entry point — stdio has no HTTP `CurrentUser`:

```php
// config/web/di.php
return [IdentitySourceInterface::class => CurrentUserIdentitySource::class];

// config/console/di.php
return [
    IdentitySourceInterface::class => static fn () => new StaticIdentitySource(
        getenv('MCP_USER_ID') ?: null,
    ),
];
```

```php
// config/params.php
'rasuvaeff/yii3-mcp' => [
    'tools' => [OrderTools::class],
    'interceptors' => [
        SessionIdentityInterceptor::class,   // outermost: binding before anything trusts the session
        RbacToolCallInterceptor::class,
    ],
    'tool_visibility' => RbacToolVisibility::class,
],
```

`AccessCheckerInterface` comes from your own RBAC setup (`yiisoft/rbac`
manager with `rbac-php`/`rbac-db` storage) — this bridge does not bind it
for you (core-doesn't-bind-the-swappable-interface principle).

## What each piece does

| Class | Role |
|---|---|
| `RbacToolCallInterceptor` | rejects `tools/call` without the mapped permission (regular MCP tool error, fail-closed for guests) |
| `RbacToolVisibility` | hides the same tools from `tools/list` — list and call can never disagree (one `PermissionMap`) |
| `SessionIdentityInterceptor` | binds the MCP session to its first identity; a leaked `Mcp-Session-Id` presented with another user's token is rejected |
| `PermissionMap` | tool name → permission: `#[RequiredPermission]` scan + explicit overrides |
| `CurrentUserIdentitySource` | identity id from `yiisoft/user`'s `CurrentUser` (`null` = guest) |
| `StaticIdentitySource` | fixed config/env identity for console/stdio |

## Two session bindings, two layers

`rasuvaeff/yii3-mcp` `^2.0` binds every session to the **MCP client** that
created it (immutable owner stamped at `initialize` — see
[Security: session ownership](/security#session-ownership)); this bridge's
`SessionIdentityInterceptor` binds the session to the **application user**
on the **first** `tools/call` — the interceptor chain does not see
`initialize`, so it cannot bind there. Between `initialize` and the first
call the session carries no user identity yet — nothing is authorized in
that window, so nothing is exposed. On core `^2.0` a foreign MCP client can
no longer slip into that window with a leaked `Mcp-Session-Id`; only a race
inside the same client remains. On core `^1.x` (no client-owner layer) the
first-call user binding is the only session protection there is.

**Session-ownership rejections bypass this bridge entirely** — see
[Bridges: a shared blind spot](/bridges/overview#a-shared-blind-spot-rejections-outside-the-chain).

## Guests and revocation

Guests are first-class: a guest binds the session as a guest and is denied
on every permission-mapped tool (`AccessCheckerInterface` receives `null`).
Permission revocation applies on the **next** call (fail-closed) — live
`notifications/tools/list_changed` on revocation is not part of this
version. Guest plus `RbacToolVisibility` can legally produce an empty
`tools/list` — an authorization result, not a registry bug; verify the
console identity binding before treating zero visible tools as a bug (see
[Operations: mcp:list](/operations#introspection-mcp-list)).

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.3 – 8.5 |
| `rasuvaeff/yii3-mcp` | `^1.1 \|\| ^2.0` |
| `yiisoft/access` | `^2.0` |
| `yiisoft/user` | `^2.0` |

Full class-by-class reference: [packages/rbac-bridge](/packages/rbac-bridge).
