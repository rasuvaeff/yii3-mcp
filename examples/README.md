# Examples

| Script | Shows | Needs server? |
|--------|-------|:-------------:|
| `yii3-wiring.php` | The package's `config/params.php` and `config/di.php` built by a real `yiisoft/di` container: the guarded endpoint, `mcp:list` and the `mcp:doctor` checks | no |

Run from the package root (after `composer install`):

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/yii3-wiring.php
```

Runtime examples are in [`rasuvaeff/mcp`](https://github.com/rasuvaeff/mcp), the
OpenAPI bridge example in [`rasuvaeff/mcp-openapi`](https://github.com/rasuvaeff/mcp-openapi).
