---
title: "What is MCP"
---

# What is MCP

[Model Context Protocol](https://modelcontextprotocol.io) (MCP) is a
JSON-RPC-based protocol that lets an AI agent — Claude Code, Claude Desktop,
or any other MCP client — call into an application's domain operations
directly, instead of guessing at them from a REST API description or scraping
a web UI. A server that speaks MCP exposes four kinds of capability:

| Capability | What it is |
|---|---|
| **Tools** | Callable operations with a typed input schema (`order.status`, `order.cancel`) — the agent calls them like functions. |
| **Resources** | Addressable, readable data (`app://orders/42`) — static or templated (`app://reports/{region}`), optionally subscribable for change notifications. |
| **Prompts** | Reusable, parameterized instruction templates the client can surface to the user (`code-review`, with a `diff` argument). |
| **Completions** | Autocompletion for a prompt argument or a resource-template variable as the user types. |

A session starts with a JSON-RPC `initialize` handshake — the client and
server negotiate a protocol revision and exchange capabilities — after which
the client calls `tools/list`, `tools/call`, `resources/read`, and so on, all
as JSON-RPC over one of two transports: **Streamable HTTP** (a PSR-15
endpoint the agent connects to over the network) or **stdio** (a local
subprocess, typically for CLI-based agents during development).

## Why an MCP endpoint instead of a REST API

An LLM agent does not read your OpenAPI spec and infer intent the way a human
developer does. MCP tools carry:

- a **typed input schema** the client validates against before calling — the
  agent sees exactly what arguments a tool accepts,
- a human-readable **description** the agent reasons about when deciding
  which tool to call,
- optional **behavior hints** (`readOnlyHint`, `destructiveHint`,
  `idempotentHint`, `openWorldHint`) so a client can decide whether a call is
  safe to retry or needs user confirmation,
- an optional **output schema**, so the agent knows the shape of the result
  before calling and the client can validate `structuredContent` against it.

`rasuvaeff/yii3-mcp` does not invent any of this — every protocol structure
(JSON-RPC, transports, sessions, the four capability kinds) comes from the
official [`mcp/sdk`](https://packagist.org/packages/mcp/sdk) (PHP Foundation +
Symfony). This package's job is wiring that SDK into Yii3: tools are ordinary
Yii3 services resolved through the DI container, and everything past the
protocol layer — session storage, authentication, interceptors, visibility —
is Yii3-idiomatic infrastructure around it.

## Where yii3-mcp sits

```
AI agent (Claude Code, Claude Desktop, …)
      │  JSON-RPC over Streamable HTTP or stdio
      ▼
SharedSecretMiddleware  (fail-closed shared-secret guard)
      ▼
McpAction / McpServeCommand  (PSR-15 handler / stdio command)
      ▼
Mcp\Server  (built by McpServerFactory)
      │
      ├─ your tool classes (#[McpTool] methods, DI-resolved)
      ├─ OpenAPI-bridged operations (OpenApiServerConfigurator)
      ├─ Markdown-file prompts (MarkdownPromptsConfigurator)
      └─ interceptor chain (budget → your interceptors → cache → size limit)
```

The three bridges — [audit log](/bridges/audit-log), [RBAC](/bridges/rbac),
[telemetry](/bridges/telemetry) — plug into the same interceptor chain as
ordinary `Interceptor\ToolCallInterceptorInterface` implementations; nothing
about them is special-cased in the core.

Continue with [Getting started](/intro/getting-started) to wire the first
tool, or read [Architecture](/intro/architecture) for how the pieces above are
actually assembled from DI config.
