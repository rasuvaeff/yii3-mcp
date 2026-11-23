---
title: "Framework-agnostic usage"
description: "rasuvaeff/mcp has no framework dependency — wire McpServerFactory, McpAction and SharedSecretMiddleware into Laravel, Symfony, Mezzio or Slim."
---

# Framework-agnostic usage

The runtime is the separate [`rasuvaeff/mcp`](https://github.com/rasuvaeff/mcp) package: PSR contracts,
`mcp/sdk` and `symfony/console`, no framework. Outside Yii3 install it directly
and wire the classes by hand; `config/di.php` and `config/params.php` of
`yii3-mcp` are only the `yiisoft/config` convenience layer:

```php
use Mcp\Server\Session\FileSessionStore;
use Rasuvaeff\Mcp\{McpAction, McpServerFactory, SharedSecretMiddleware};

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
    interceptors: [new ToolCallBudgetInterceptor(50), $tracingInterceptor],
    toolVisibility: $planBasedVisibility,
);
```

Every piece documented in [Capabilities](/capabilities),
[Interceptors](/interceptors), [Visibility](/visibility), and the
[OpenAPI bridge](/openapi-bridge) is a plain constructor call — nothing
there is Yii3-specific either.
