---
name: rasuvaeff-yii3-mcp
description: >-
  Expose Yii3 application services as MCP tools/resources over the official
  mcp/sdk — McpServerFactory, McpAction (PSR-15 Streamable HTTP),
  SharedSecretMiddleware, tool-call interceptors (SessionBudgetInterceptor,
  RateLimitInterceptor, ArgumentMasker), tool visibility, OpenAPI bridge,
  Testing\McpTester + SchemaSnapshot. Use when writing, reviewing or debugging
  MCP server code in a project that has this package installed.
---

# rasuvaeff/yii3-mcp

MCP server integration for Yii3: tool classes are listed in params, resolved
through the DI container, served over PSR-15 Streamable HTTP or stdio.
Namespace `Rasuvaeff\Yii3Mcp\`. Protocol structures (attributes, JSON-RPC,
sessions) come from `mcp/sdk` (`~0.6.0`, minor = breaking) — never invent them.

## Safety rules — verify these on every change

1. **The endpoint is trusted-only; `endpoint_secret` is mandatory.** Route
   `McpAction` behind `SharedSecretMiddleware`. An empty secret means every
   request gets an explanatory 503 (fail-closed) — never "fix" that into a
   pass-through, and never put the raw secret in logs or responses.

2. **Tool visibility is fail-closed on both paths.** A hidden tool is omitted
   from `tools/list` AND rejected on `tools/call` ("not available in this
   session") before interceptors or the tool run. Same for hidden
   prompts/resources — reported as not found. Don't filter only the list.

3. **Set a session budget against agent loops.** Params
   `'session' => ['budget' => N]` (default 0 = unlimited) makes
   `SessionBudgetInterceptor` fail a session's calls past N. It is anti-loop,
   NOT a client quota — a new session resets the counter; for real quotas
   implement `ToolCallLimiterInterface` + `RateLimitInterceptor` (limiter
   failure is fail-closed, never silently unlimited).

4. **Mask arguments before logging/tracing/auditing.** Never log
   `ToolCallContext::$arguments` raw — pass them through `ArgumentMasker`
   (`password`, `secret`, `token`, `api_key`, `credit_card` by default; every
   nesting level).

5. **OpenAPI bridge rejects unsafe operations at build time.** Operations are
   an explicit allow-list of operationIds (empty = nothing exposed);
   `safeMethodsOnly: true` throws `UnsafeOperationException` for non-GET.
   Don't widen either default to "expose everything".

6. **Sessions must stay FPM-safe.** The shipped default is `FileSessionStore`;
   the SDK's in-memory store silently loses sessions between FPM workers.

## Canonical usage

```php
use Mcp\Capability\Attribute\McpTool;
use Rasuvaeff\Yii3Mcp\{McpServerFactory, McpAction, SharedSecretMiddleware};

final readonly class OrderTools
{
    public function __construct(private OrderRepository $orders) {} // DI works

    #[McpTool(name: 'order.status')]
    public function status(string $orderId): string { ... }
}

$factory = new McpServerFactory(container: $c, sessionStore: $store, name: 'my-app', version: '1.0.0');
$server = $factory->create([OrderTools::class]);
// route POST/GET/DELETE/OPTIONS /mcp:
// SharedSecretMiddleware(secret: ..., responseFactory: $psr17) -> McpAction(server: ..., ...)
```

Testing without HTTP: `Testing\McpTester` (`callTool`, `listTools`, ...);
schema drift gate: `Testing\SchemaSnapshot::verify($tester, $file)` in CI.

## Full API

The complete reference — interceptor/visibility params, OpenAPI bridge rules,
`mcp:serve`/`mcp:list`/`mcp:doctor` commands, client identity and secret
rotation — ships with the package: read `vendor/rasuvaeff/yii3-mcp/llms.txt`
before guessing a class, param key or method name.
