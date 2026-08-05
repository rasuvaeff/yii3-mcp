---
title: "rasuvaeff/yii3-mcp-telemetry-bridge"
description: "rasuvaeff/yii3-mcp-telemetry-bridge — tracing and metrics interceptors for yii3-mcp. Requirements and public API."
---

# rasuvaeff/yii3-mcp-telemetry-bridge

Observability for [yii3-mcp](/packages/core) servers: a trace span and
RED-style metrics for every `tools/call`, via
[`rasuvaeff/yii3-telemetry`](https://github.com/rasuvaeff/yii3-telemetry)
and [`rasuvaeff/yii3-metrics`](https://github.com/rasuvaeff/yii3-metrics).
See the [bridge guide](/bridges/telemetry) for full usage.

```bash
composer require rasuvaeff/yii3-mcp-telemetry-bridge
```

| | |
|---|---|
| Namespace | `Rasuvaeff\Yii3McpTelemetryBridge` |
| Repository | [github.com/rasuvaeff/yii3-mcp-telemetry-bridge](https://github.com/rasuvaeff/yii3-mcp-telemetry-bridge) |
| Requirements | PHP 8.3 – 8.5, `rasuvaeff/yii3-mcp ^1.6 \|\| ^2.0`, `rasuvaeff/yii3-telemetry ^1.0`, `rasuvaeff/yii3-metrics ^1.0` |

## Public API

| Class | Role |
|---|---|
| [`TracingToolCallInterceptor`](/api/classes/TracingToolCallInterceptor) | One `mcp.tool <name>` span per `tools/call`, with masked argument attributes. |
| [`MetricsToolCallInterceptor`](/api/classes/MetricsToolCallInterceptor) | `mcp_tool_calls_total` counter + `mcp_tool_call_duration_seconds` histogram. |

See [Bridges: telemetry](/bridges/telemetry) for the span/metric field
tables, Fiber propagation notes, and masking semantics.
