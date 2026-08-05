---
title: "Bridges: overview"
---

# Bridges: overview

Three companion packages plug into the core's [interceptor chain](/interceptors)
as ordinary `Interceptor\ToolCallInterceptorInterface` (and, for RBAC, also
`Visibility\ToolVisibilityInterface`) implementations — nothing about them is
special-cased inside `rasuvaeff/yii3-mcp` itself. All three are orthogonal
and can be installed together.

| Bridge | Answers | Adds |
|---|---|---|
| [Audit log](/bridges/audit-log) | "What did the AI actually do?" | One audit event per `tools/call`, actor-resolvable to a user. |
| [RBAC](/bridges/rbac) | "What is this specific user allowed to do?" | Per-user permission enforcement + filtered `tools/list` + session-identity binding. |
| [Telemetry](/bridges/telemetry) | "Is the MCP server healthy, and how is it being used?" | A trace span + RED metrics per `tools/call`. |

## Combining them: interceptor order

Register whichever combination you need in `interceptors`, then let the core
prepend session budget and append caching/size-limit automatically (see
[Interceptors: chain order](/interceptors#the-chain-order-is-fixed-and-load-bearing)):

```php
'rasuvaeff/yii3-mcp' => [
    'interceptors' => [
        SessionIdentityInterceptor::class,     // RBAC bridge — outermost of the three: binds
                                                // identity before anything else trusts the session
        TracingToolCallInterceptor::class,     // telemetry — outermost of the observability pair,
                                                // so the span covers RBAC and audit too
        MetricsToolCallInterceptor::class,     // telemetry
        AuditTrailInterceptor::class,          // audit log — records the RBAC decision's outcome too
        RbacToolCallInterceptor::class,        // RBAC — the actual permission check
    ],
    'tool_visibility' => RbacToolVisibility::class,
],
```

There is no single mandated order across bridges beyond each bridge's own
internal constraint (`SessionIdentityInterceptor` outermost within RBAC,
`TracingToolCallInterceptor` outermost within telemetry so its span covers
everything nested inside it). Placing tracing before audit means a rejected
call still gets a span with `mcp.outcome=rejected`; placing audit before
RBAC means the audit trail records the RBAC decision as its outcome.

## A shared blind spot: rejections outside the chain

All three bridges observe interceptors, and interceptors run **inside** the
chain the core builds. Two kinds of rejection happen **before** that chain
runs at all, and are therefore invisible to every bridge simultaneously:

1. **Session-budget exhaustion.** `SessionBudgetInterceptor` is always the
   outermost interceptor, added by the core itself outside anything
   configured in `interceptors` — see
   [Interceptors: session budget](/interceptors#session-budget-stop-agent-loops).
2. **Session-ownership rejection.** On `rasuvaeff/yii3-mcp` `^2.0`, a
   foreign or ownerless session is rejected by `McpAction` /
   `InterceptingReferenceHandler` before the interceptor chain runs at all —
   see [Security: session ownership](/security#session-ownership).

Neither produces an audit event, a span, a metric, or an RBAC decision. An
exhausted budget looks like the agent going silent; a hijack attempt stopped
by the core shows up only in application/web-server logs. This is
documented in each bridge's own page, not a bug in any of them — a session
that never entered the chain has nothing for a chain-scoped interceptor to
observe.

## A shared vocabulary: `CallOutcome`

Both the audit and telemetry bridges classify a call's result through the
core's `Interceptor\CallOutcome` (`success` / `rejected` / `error`,
`fromThrowable()`) — a `ToolCallException` (RBAC denial, rate limit, session
budget) is `rejected`, never conflated with an unexpected crash (`error`).
This is what lets an audit query and a metrics alert agree on what "the
call was refused" means, and it is also what the [RBAC bridge](/bridges/rbac)'s
denials feed into when audit runs alongside it.

## A shared helper: `ArgumentMasker`

Both the audit bridge (via `rasuvaeff/yii3-audit-log`'s own
`SensitiveValueMasker`, field-name-only, not recursive) and the telemetry
bridge (via the core's `Interceptor\ArgumentMasker`, recursive) mask
sensitive tool arguments before they leave the process — see
[Interceptors: masking sensitive arguments](/interceptors#masking-sensitive-arguments).
The two masking semantics are documented separately on each bridge's page;
they are not identical, so check which one applies to your audit vs. trace
storage.
