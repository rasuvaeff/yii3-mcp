---
title: "Telemetry bridge"
---

# Telemetry bridge

`rasuvaeff/yii3-mcp-telemetry-bridge` adds observability: a trace span and
RED-style metrics for every `tools/call`, via
[`rasuvaeff/yii3-telemetry`](https://github.com/rasuvaeff/yii3-telemetry)
and [`rasuvaeff/yii3-metrics`](https://github.com/rasuvaeff/yii3-metrics).
Both observability cores are vendor-neutral — wire a backend
(`yii3-telemetry-otel`, `yii3-metrics-prometheus`) or their `Null*`
providers.

## Install and wire

```bash
composer require rasuvaeff/yii3-mcp-telemetry-bridge
```

```php
// config/params.php
use Rasuvaeff\Yii3McpTelemetryBridge\{MetricsToolCallInterceptor, TracingToolCallInterceptor};

'rasuvaeff/yii3-mcp' => [
    'interceptors' => [
        TracingToolCallInterceptor::class,   // outermost: the span should cover RBAC/audit too
        MetricsToolCallInterceptor::class,
    ],
],
```

Use either interceptor alone if only one of the two stacks runs.

## Tracing: `TracingToolCallInterceptor`

Every `tools/call` becomes one span:

| Span field | Value |
|---|---|
| name | `mcp.tool <tool name>` (e.g. `mcp.tool order.status`) |
| `mcp.tool` | tool name |
| `mcp.tool.argument.<name>` | one scalar attribute per argument: masked (`***`), stringified (arrays as JSON), truncated at 200 bytes |
| `mcp.outcome` | `success` / `rejected` / `error` |
| `mcp.client.id` | identity from the endpoint secret; absent on stdio |
| `mcp.client.name` / `mcp.client.version` | client from the initialize handshake |
| `mcp.session.id` | MCP session UUID |
| `mcp.session.calls_used` | tools/call count in this session (when the session budget is on) |
| `mcp.session.budget_remaining` | remaining budget (when `sessionBudget` is configured) |
| status | `Error` + recorded exception on failure; `Unset` on success |

A tool exception is recorded on the span and **rethrown** — the MCP error
envelope the agent sees is unchanged. Arguments are flattened to
per-argument **scalar** attributes deliberately: the OTel attribute model
accepts only primitives and homogeneous lists, so a single nested-array
attribute would be silently dropped by an OTel backend.

```php
$interceptor = new TracingToolCallInterceptor(
    tracer: $tracer,                          // Rasuvaeff\Yii3Telemetry\TracerInterface
    argumentMasker: new ArgumentMasker(),     // core's masker — default key list: password, secret, token, api_key, credit_card
    sessionBudget: 50,                        // optional: mirror your `session.budget` param
);
```

`sessionBudget` only feeds `mcp.session.budget_remaining` — enforcement
stays entirely in the core's `SessionBudgetInterceptor`. Since an `int`
cannot be autowired, mirror the `session.budget` param in a DI factory:

```php
// config/common/di/mcp-telemetry.php
TracingToolCallInterceptor::class => static function (TracerInterface $tracer) use ($params) {
    $budget = $params['rasuvaeff/yii3-mcp']['session']['budget'] ?? 0;

    return new TracingToolCallInterceptor($tracer, sessionBudget: $budget);
},
```

`null` and the core default `0` both mean unlimited and omit the attribute;
every positive value publishes it; negative values are rejected.

### OpenTelemetry and SDK Fibers

`mcp/sdk` runs request handlers in Fibers. The supported concurrent
deployment uses OTel automatic Fiber propagation: non-thread-safe PHP,
`ext-ffi`, `OTEL_PHP_FIBERS_ENABLED=true`, and (where FPM loads the observer
too late) a preloaded `vendor/autoload.php`. Verify with:

```bash
MCP_OTEL_FIBER_TEST=1 vendor/bin/testo --suite=Integration
```

For strictly sequential PHP-FPM only, a limited fallback is
`Context::setStorage(new ContextStorage())` before tracing starts — it
shares a single current context and is **unsafe** for event loops or any
Fiber that can suspend/resume concurrently.

## Metrics: `MetricsToolCallInterceptor`

| Metric | Type | Labels |
|---|---|---|
| `mcp_tool_calls_total` | counter | `tool`, `outcome` |
| `mcp_tool_call_duration_seconds` | histogram | `tool` |

Duration is the wall time of the wrapped chain (`hrtime()`), observed on
both success and failure. The histogram deliberately carries **no**
`outcome` label to keep cardinality low — errors are counted in the
counter instead.

```php
$interceptor = new MetricsToolCallInterceptor(
    metrics: $registry,                        // Rasuvaeff\Yii3Metrics\MetricRegistry
    durationBuckets: [0.05, 0.1, 0.5, 1.0],    // optional; Prometheus-style defaults otherwise
);
```

## What the telemetry does NOT see

Session-budget exhaustion and session-ownership rejections are both
invisible — shared with every other bridge, see
[Bridges: a shared blind spot](/bridges/overview#a-shared-blind-spot-rejections-outside-the-chain).
An exhausted budget looks like traffic dropping to zero — watch
`mcp.session.budget_remaining` on the calls that *do* go through. A
rejection classified `rejected` (RBAC denial, rate limit, session budget) is
distinct from `error` — alert on `outcome="error"` for crashes only.

## stdio mode (`mcp:serve`)

The stdio worker is long-running: ensure your tracing backend exports spans
per call rather than only on process shutdown (for `yii3-telemetry-otel`,
use a batch processor with a scheduled delay or a simple processor) —
otherwise spans buffer until the agent disconnects.

## Masking

Arguments land on the span masked by field name (case-insensitive, at
**every nesting level**) via the core's `Interceptor\ArgumentMasker` — see
[Interceptors: masking sensitive arguments](/interceptors#masking-sensitive-arguments).
Extend the key list via the constructor for application-specific fields.
This differs from the [audit-log bridge](/bridges/audit-log)'s masking
(field-name-only, not recursive) — see
[Bridges: a shared helper](/bridges/overview#a-shared-helper-argumentmasker).

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.3 – 8.5 |
| `rasuvaeff/yii3-mcp` | `^1.6 \|\| ^2.0` |
| `rasuvaeff/yii3-telemetry` | `^1.0` |
| `rasuvaeff/yii3-metrics` | `^1.0` |

Full class-by-class reference: [packages/telemetry-bridge](/packages/telemetry-bridge).
