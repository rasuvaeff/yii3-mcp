---
title: "Cookbook: your first MCP server"
---

# Cookbook: your first MCP server

A worked walkthrough from an empty config to a verified, drift-guarded MCP
server — the checks a real deployment should pass before a client ever
connects.

## 1. Write and register a tool

```php
use Mcp\Capability\Attribute\McpTool;

final readonly class OrderTools
{
    public function __construct(private OrderRepository $orders) {}

    /** Returns the current status of an order. */
    #[McpTool(name: 'order.status')]
    public function status(string $orderId): string
    {
        return $this->orders->get($orderId)->status->value;
    }
}
```

```php
// config/params.php
'rasuvaeff/yii3-mcp' => [
    'server_name' => 'my-app',
    'server_version' => '1.0.0',
    'tools' => [OrderTools::class],
    'endpoint_secret' => getenv('MCP_SECRET'),
],
```

See [Getting started](/intro/getting-started) for the routing step.

## 2. Confirm it's actually served

```bash
./yii mcp:list
```

`mcp:list` drives the same in-process JSON-RPC path a real client would —
if `order.status` shows up here with the right argument summary
(`orderId*`), the wiring is correct end to end, without needing a network
client at all. Remember this is the **unauthenticated default view**: with
[visibility](/visibility) or the [RBAC bridge](/bridges/rbac) configured, a
real client may see less.

## 3. Run `mcp:doctor` before shipping

```bash
./yii mcp:doctor
```

This catches the class of mistake `mcp:list` can't: a missing PSR service in
the console DI group, an insecure session directory, a misconfigured
secret. See [Operations: mcp:doctor](/operations#diagnostics-mcp-doctor)
for what each check covers and the exit-code meanings — wire it into a
deploy health check with `--json` and the exit code, not by parsing the
human-readable table.

## 4. Write a test with `McpTester`

```php
use Rasuvaeff\Yii3Mcp\Testing\McpTester;

$tester = new McpTester($server, $psr17, $psr17, $psr17);

$result = $tester->callTool('order.status', ['orderId' => '42']);
Assert::same('paid', $result['content'][0]['text']);
```

`McpTester` drives the real Streamable HTTP code path in-process — no HTTP
server, no stdio process. See [Capabilities](/capabilities) for what else
it can call (`listTools()`, `listResources()`, `readResource()`, raw
`request()`).

## 5. Pin the contract with a schema snapshot

A changed method signature silently changes the generated `inputSchema` —
and can break an agent mid-flight without any test noticing, unless the
schema itself is under test:

```php
use Rasuvaeff\Yii3Mcp\Testing\SchemaSnapshot;

SchemaSnapshot::verify($tester, __DIR__ . '/mcp-schema.json');
```

`verify()` treats a missing snapshot file as an **error** — a deleted or
never-committed file cannot yield a green CI build. Create or deliberately
regenerate it once, then commit:

```bash
MCP_SNAPSHOT_RECORD=1 vendor/bin/testo --suite=Unit
git add tests/mcp-schema.json
```

When bumping the `mcp/sdk` pin, expect to regenerate — schema serialization
may legitimately change between SDK minors.

## 6. Point a real client at it

```json
{
    "mcpServers": {
        "my-app": {
            "type": "http",
            "url": "https://example.com/mcp",
            "headers": { "X-Mcp-Secret": "..." }
        }
    }
}
```

Or for local development, stdio: `claude mcp add my-app -- ./yii mcp:serve`.
For interactive poking, the official MCP Inspector
(`npx @modelcontextprotocol/inspector`) works against the same Streamable
HTTP endpoint.

## Where to go next

- Add [interceptors](/interceptors) for tracing, rate limiting, or a custom
  ACL.
- Bring in the [audit log](/bridges/audit-log), [RBAC](/bridges/rbac), or
  [telemetry](/bridges/telemetry) bridge.
- If the application already has an OpenAPI spec, see
  [Bridging an existing REST API](/cookbook/bridging-existing-api) instead
  of hand-writing tools.
