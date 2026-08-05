---
title: "rasuvaeff/yii3-mcp"
---

# rasuvaeff/yii3-mcp

The core package: MCP server integration for Yii3 over the official
[`mcp/sdk`](https://packagist.org/packages/mcp/sdk) — a PSR-15 Streamable
HTTP endpoint, a stdio transport, and a DI-resolved tool registry.

```bash
composer require rasuvaeff/yii3-mcp
```

| | |
|---|---|
| Namespace | `Rasuvaeff\Yii3Mcp` |
| Repository | [github.com/rasuvaeff/yii3-mcp](https://github.com/rasuvaeff/yii3-mcp) |
| Requirements | PHP 8.3 – 8.5, `mcp/sdk ~0.7.0`, `ext-fileinfo` |

## Where to start

- [What is MCP](/intro/what-is-mcp) and [Getting started](/intro/getting-started)
- [Architecture](/intro/architecture) — how `McpServerFactory` assembles the server
- [Capabilities](/capabilities), [Security](/security), [Interceptors](/interceptors), [Visibility](/visibility)
- [OpenAPI bridge](/openapi-bridge), [MCP Apps](/apps), [Multi-tenant serving](/multi-tenant)
- [Operations](/operations) — `mcp:list` / `mcp:doctor`
- [Framework-agnostic usage](/framework-agnostic)

## Key classes

| Class | Role |
|---|---|
| [`McpServerFactory`](/api/classes/McpServerFactory) | List of tool FQCNs → configured SDK `Server`. |
| [`McpAction`](/api/classes/McpAction) | PSR-15 handler running the Streamable HTTP transport; enforces session ownership. |
| [`SharedSecretMiddleware`](/api/classes/SharedSecretMiddleware) | Fail-closed shared-secret guard; resolves client identity. |
| [`McpServeCommand`](/api/classes/McpServeCommand) | `mcp:serve` — stdio transport. |
| [`McpListCommand`](/api/classes/McpListCommand) | `mcp:list` — introspection. |
| [`McpDoctorCommand`](/api/classes/McpDoctorCommand) | `mcp:doctor` — configuration health check. |
| [`Testing\McpTester`](/api/classes/McpTester) | In-process test client. |
| [`Testing\SchemaSnapshot`](/api/classes/SchemaSnapshot) | Committed-snapshot contract-drift guard. |

See the full [API reference](/api/index) for every `@api` class.

## Bridges

Three companion packages extend this core without any special-casing —
see [Bridges: overview](/bridges/overview):
[audit log](/packages/audit-log-bridge),
[RBAC](/packages/rbac-bridge),
[telemetry](/packages/telemetry-bridge).

## Roadmap

See [Roadmap](/roadmap) for planned work beyond v1.0/v2.0, in dependency
order.
