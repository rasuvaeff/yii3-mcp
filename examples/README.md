# Examples

| Script | Shows | Needs server? |
|--------|-------|:-------------:|
| [`http-handshake.php`](http-handshake.php) | Full MCP cycle in-process: `initialize` handshake + `tools/call` through `McpAction` with a container-resolved tool | no |

Run from the package root (after `composer install`):

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/http-handshake.php
```
