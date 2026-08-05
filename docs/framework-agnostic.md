---
title: "Framework-agnostic usage"
---

# Framework-agnostic usage

Despite the package name, the code has no `yiisoft/*` **runtime**
dependency. `composer.json`'s `require` is PSR interfaces
(`psr/container`, `psr/http-message`, `psr/http-server-middleware`,
`psr/http-factory`, `psr/simple-cache`, `psr/log`) plus `mcp/sdk` and
`symfony/console`/`yaml`. `McpServerFactory` takes a plain
`Psr\Container\ContainerInterface`; `McpAction` and `SharedSecretMiddleware`
are plain PSR-15. `config/di.php` + `config/params.php` are only the
`yiisoft/config-plugin` convenience layer — outside Yii3, wire the same
classes by hand with whatever PSR-11 container and router the application
already uses (Laravel, Symfony, Mezzio, Slim, …):

```php
use Mcp\Server\Session\FileSessionStore;
use Rasuvaeff\Yii3Mcp\{McpAction, McpServerFactory, SharedSecretMiddleware};

// any PSR-11 container — Yii3's, PHP-DI, Laravel's, a hand-rolled one
$container = /* ... */;

$sessionStore = new FileSessionStore(directory: sys_get_temp_dir() . '/mcp-sessions', ttl: 3600);
$factory = new McpServerFactory(container: $container, sessionStore: $sessionStore, name: 'my-app', version: '1.0.0');
$server = $factory->create([OrderTools::class]);

$psr17 = /* any PSR-17 factory, e.g. nyholm/psr7 or guzzlehttp/psr7 */;
$action = new McpAction(server: $server, responseFactory: $psr17, streamFactory: $psr17);
$middleware = new SharedSecretMiddleware(secret: getenv('MCP_SECRET'), responseFactory: $psr17);

// route POST/GET/DELETE/OPTIONS /mcp through $middleware -> $action
// in whatever middleware-dispatch shape the framework's router expects
```

`McpServeCommand` (stdio) extends `Symfony\Component\Console\Command\Command`
directly and needs no Yii3 console application — add it to any
`Symfony\Component\Console\Application`.

## What's lost without `yiisoft/config`

The automatic array-merge across packages — interceptors, visibility, the
OpenAPI bridge assembled from several `config/params.php` files. Construct
`Interceptor\*` / `Visibility\*` / `OpenApi\OpenApiServerConfigurator`
instances directly and pass them to `McpServerFactory::create()` — the same
objects `config/di.php` builds from params inside Yii3, just without the
config-plugin assembling them automatically:

```php
$server = $factory->create(
    toolClasses: [OrderTools::class],
    configurators: [$openApiServerConfigurator],
    interceptors: [new SessionBudgetInterceptor(50), $tracingInterceptor],
    toolVisibility: $planBasedVisibility,
);
```

Every piece documented in [Capabilities](/capabilities),
[Interceptors](/interceptors), [Visibility](/visibility), and the
[OpenAPI bridge](/openapi-bridge) is a plain constructor call — nothing
there is Yii3-specific either.
