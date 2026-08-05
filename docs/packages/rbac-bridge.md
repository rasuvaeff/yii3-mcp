---
title: "rasuvaeff/yii3-mcp-rbac-bridge"
---

# rasuvaeff/yii3-mcp-rbac-bridge

Per-user authorization for [yii3-mcp](/packages/core) servers over the Yii3
auth stack: RBAC permissions enforced on every `tools/call`,
permission-aware `tools/list` filtering, and session-identity binding
against session hijacking. See the [bridge guide](/bridges/rbac) for full
usage.

```bash
composer require rasuvaeff/yii3-mcp-rbac-bridge
```

| | |
|---|---|
| Namespace | `Rasuvaeff\Yii3McpRbacBridge` |
| Repository | [github.com/rasuvaeff/yii3-mcp-rbac-bridge](https://github.com/rasuvaeff/yii3-mcp-rbac-bridge) |
| Requirements | PHP 8.3 – 8.5, `rasuvaeff/yii3-mcp ^1.1 \|\| ^2.0`, `yiisoft/access ^2.0`, `yiisoft/user ^2.0` |

## Public API

| Class | Role |
|---|---|
| [`RequiredPermission`](/api/classes/RequiredPermission) | Attribute: maps a `#[McpTool]` method to a permission. |
| [`PermissionMap`](/api/classes/PermissionMap) | Tool name → permission, built from attributes and/or explicit overrides. |
| [`RbacToolCallInterceptor`](/api/classes/RbacToolCallInterceptor) | Rejects `tools/call` without the mapped permission. |
| [`RbacToolVisibility`](/api/classes/RbacToolVisibility) | Hides the same tools from `tools/list`. |
| [`SessionIdentityInterceptor`](/api/classes/SessionIdentityInterceptor) | Binds the MCP session to its first identity. |
| [`IdentitySourceInterface`](/api/classes/IdentitySourceInterface) | Port for "who is the current user." |
| [`CurrentUserIdentitySource`](/api/classes/CurrentUserIdentitySource) | Adapter over `yiisoft/user`'s `CurrentUser`. |
| [`StaticIdentitySource`](/api/classes/StaticIdentitySource) | Fixed config/env identity for console/stdio. |

See [Bridges: RBAC](/bridges/rbac) for the two-auth-layers model, tool-name
key derivation, and the two-session-bindings security model.
