---
title: "rasuvaeff/yii3-mcp-audit-log-bridge"
description: "rasuvaeff/yii3-mcp-audit-log-bridge — audit trail interceptor and actor resolvers for yii3-mcp. Requirements and public API."
---

# rasuvaeff/yii3-mcp-audit-log-bridge

Records every [yii3-mcp](/packages/core) `tools/call` into
[`rasuvaeff/yii3-audit-log`](https://github.com/rasuvaeff/yii3-audit-log) —
the answer to "what did the AI actually do in our system." See the
[bridge guide](/bridges/audit-log) for full usage.

```bash
composer require rasuvaeff/yii3-mcp-audit-log-bridge
```

| | |
|---|---|
| Namespace | `Rasuvaeff\Yii3McpAuditLogBridge` |
| Repository | [github.com/rasuvaeff/yii3-mcp-audit-log-bridge](https://github.com/rasuvaeff/yii3-mcp-audit-log-bridge) |
| Requirements | PHP 8.3 – 8.5, `rasuvaeff/yii3-mcp ^1.6 \|\| ^2.0`, `rasuvaeff/yii3-audit-log ^1.0` |
| Optional | `rasuvaeff/yii3-mcp-rbac-bridge ^1.0`, only for `IdentityAuditActorResolver` |

## Public API

| Class | Role |
|---|---|
| [`AuditTrailInterceptor`](/api/classes/AuditTrailInterceptor) | `ToolCallInterceptorInterface` — records the event, rethrows failures unchanged. |
| [`AuditActorResolverInterface`](/api/classes/AuditActorResolverInterface) | Decides WHO the actor is. |
| [`ClientAuditActorResolver`](/api/classes/ClientAuditActorResolver) | Default — credits the MCP connection (session + handshake client). |
| [`IdentityAuditActorResolver`](/api/classes/IdentityAuditActorResolver) | Credits the authenticated application user, via the RBAC bridge's `IdentitySourceInterface`. |

See [Bridges: audit log](/bridges/audit-log) for wiring, the recorded field
table, actor resolution, and masking semantics.
