---
layout: home
title: Yii3 MCP
description: MCP server for Yii3 — expose your application's tools, resources and prompts to AI agents like Claude Code and Claude Desktop over Streamable HTTP.
hero:
  name: Yii3 MCP
  text: Expose your Yii3 application to AI agents, safely.
  tagline: MCP server integration for Yii3 over the official mcp/sdk — core + audit log, RBAC, and telemetry bridges.
  image:
    src: /logo-mark.svg
    alt: Yii3 MCP logo
  actions:
    - theme: brand
      text: What is MCP?
      link: /intro/what-is-mcp
    - theme: alt
      text: Getting started
      link: /intro/getting-started
    - theme: alt
      text: View on GitHub
      link: https://github.com/rasuvaeff/yii3-mcp
features:
  - title: Tools are ordinary Yii3 services
    details: Public methods annotated with the SDK's own #[McpTool]/#[McpResource]/#[McpPrompt] attributes — DI-resolved, no protocol structures invented on top.
    link: /capabilities
  - title: Fail-closed by default
    details: An empty shared secret rejects every request with an explanatory 503. Sessions are bound to the client that created them, enforced in two places.
    link: /security
  - title: One interceptor chain, fixed order
    details: Session budget, your interceptors, caching, size limit — in that order, always. Caching can never bypass RBAC or audit.
    link: /interceptors
  - title: Bridge an existing REST API
    details: Allow-listed OpenAPI operations become MCP tools with zero duplication — real HTTP calls through the upstream's own middleware stack.
    link: /openapi-bridge
  - title: Audit, RBAC, telemetry — orthogonal bridges
    details: Three companion packages plug into the same interceptor chain as ordinary interceptors. Combine any subset.
    link: /bridges/overview
  - title: Diagnose before you debug
    details: mcp:doctor checks the whole configuration end-to-end with stable exit codes; mcp:list shows exactly what's served, without a client.
    link: /operations
---

<div class="vp-doc" style="max-width: 960px; margin: 3rem auto 0; padding: 0 24px;">

## The whole stack

```
AI agent (Claude Code, Claude Desktop, …)
      │  JSON-RPC over Streamable HTTP or stdio
      ▼
SharedSecretMiddleware  ── fail-closed shared-secret guard
      ▼
McpAction / McpServeCommand
      ▼
Mcp\Server  ── built by McpServerFactory
      │
      ├─ your tool classes             (#[McpTool] methods, DI-resolved)
      ├─ OpenAPI-bridged operations    (OpenApiServerConfigurator)
      ├─ Markdown-file prompts         (MarkdownPromptsConfigurator)
      └─ interceptor chain
           budget → [tracing → audit → RBAC] → cache → size limit
```

The bracketed interceptors are the three bridges — nothing about them is
special-cased in the core; each is an ordinary
`Interceptor\ToolCallInterceptorInterface`.

## Four packages

:::code-group

```php [core]
use Mcp\Capability\Attribute\McpTool;

final readonly class OrderTools
{
    public function __construct(private OrderRepository $orders) {}

    #[McpTool(name: 'order.status')]
    public function status(string $orderId): string
    {
        return $this->orders->get($orderId)->status->value;
    }
}
```

```php [rbac-bridge]
use Rasuvaeff\Yii3McpRbacBridge\RequiredPermission;

#[McpTool(name: 'order.status')]
#[RequiredPermission('orders.view')]
public function status(string $orderId): string { /* … */ }
```

```php [audit-log-bridge]
'rasuvaeff/yii3-mcp' => [
    'interceptors' => [AuditTrailInterceptor::class],
],
// every tools/call now writes an audit event: actor, tool, arguments,
// outcome, duration — masked, rethrown on failure
```

```php [telemetry-bridge]
'rasuvaeff/yii3-mcp' => [
    'interceptors' => [
        TracingToolCallInterceptor::class,
        MetricsToolCallInterceptor::class,
    ],
],
// mcp.tool <name> span + mcp_tool_calls_total / mcp_tool_call_duration_seconds
```

:::

See [Bridges: overview](/bridges/overview) for how they combine, and
[Getting started](/intro/getting-started) to wire the core on its own.

</div>
