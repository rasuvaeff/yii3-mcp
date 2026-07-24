# Examples

| Script | Shows | Needs server? |
|--------|-------|:-------------:|
| `http-handshake.php` | Full MCP cycle in-process: `initialize` handshake + `tools/call` through `McpAction` with a container-resolved tool | no |
| `stdio-serve.php` | The stdio transport `mcp:serve` runs — line-delimited JSON-RPC over in-memory streams | no |
| `conditional.php` | `ConditionalToolInterface`: the same class registered or skipped depending on `shouldRegister()` | no |
| `prompts.php` | [`prompts/`](prompts/) directory of Markdown files served as MCP prompts: `prompts/list` + rendered `prompts/get` | no |
| `openapi-bridge.php` | Allow-listed OpenAPI operations bridged as MCP tools; the call becomes a real HTTP request (stubbed PSR-18 client) | no |
| `interceptors.php` | Tool-call interceptor chain: a tracing interceptor (arguments masked via `ArgumentMasker`) + the session budget guard rejecting the third call | no |
| `visibility.php` | Tool visibility: per-session interface AND the declarative deny/allow patterns; hidden tools fail-closed rejected on call | no |
| `capability-hooks.php` | prompts/get + resources/read interceptor chains (`CallOutcome`, template variables, client-visible rejection) and prompt visibility hiding a prompt as not-found | no |
| `structured-output.php` | `outputSchema` on `#[McpTool]`: typed result served in `tools/list`, array return mirrored into `structuredContent` | no |

Run from the package root (after `composer install`):

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/http-handshake.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/stdio-serve.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/conditional.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/prompts.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/openapi-bridge.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/interceptors.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/visibility.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/structured-output.php
```
