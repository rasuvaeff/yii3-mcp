# Examples

| Script | Shows | Needs server? |
|--------|-------|:-------------:|
| `openapi-bridge.php` | Allow-listed OpenAPI operations bridged as MCP tools via `OpenApiBridgeFactory::create()` (no Yii3 application) — `tool_names` rename, `OperationModifierInterface`, `readOnlyHint`/tags in `_meta`, `dryRunOperations` preview; the call becomes a real HTTP request (stubbed PSR-18 client) | no |

The runtime examples are in [`rasuvaeff/mcp`](https://github.com/rasuvaeff/mcp).

Run from the package root (after `composer install`):

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/openapi-bridge.php
```
