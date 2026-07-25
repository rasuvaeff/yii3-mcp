# Интеграция MCP-стека в Yii3

Проверенный runbook для `yii3-mcp`, RBAC, audit, tracing и metrics. Core-пакеты
приходят транзитивно; root-приложение явно выбирает leaf bridges/backends.

## Установка и граф зависимостей

```bash
composer require \
  rasuvaeff/yii3-mcp-rbac-bridge \
  rasuvaeff/yii3-mcp-audit-log-bridge \
  rasuvaeff/yii3-mcp-telemetry-bridge \
  rasuvaeff/yii3-audit-log-db \
  rasuvaeff/yii3-telemetry-otel \
  rasuvaeff/yii3-metrics-prometheus \
  guzzlehttp/guzzle nyholm/psr7
```

```text
yii3-mcp-rbac-bridge ───────────────┐
yii3-mcp-audit-log-bridge ──────────┼──> yii3-mcp
yii3-mcp-telemetry-bridge ──────────┘       │
        │                                   └──> mcp/sdk
        ├──> yii3-telemetry <── yii3-telemetry-otel
        └──> yii3-metrics   <── yii3-metrics-prometheus
yii3-mcp-audit-log-bridge ──> yii3-audit-log <── yii3-audit-log-db
```

Не удаляйте direct leaf dependency как "unused": именно приложение выбирает
единственный backend. Для Composer Dependency Analyser ограничьте исключение
конкретным пакетом через `ignoreErrorsOnPackage(...,
[ErrorType::UNUSED_DEPENDENCY])`. `composer-require-checker` unused packages не
проверяет.

## Config groups и PSR services

`Mcp\Server` и все его interceptors должны собираться и в web, и в console
group. В частности, audit writer требует `ClockInterface` в обеих группах.

| Entry point / feature | Обязательные services |
|---|---|
| `McpAction` | `ResponseFactoryInterface`, `StreamFactoryInterface` |
| `McpListCommand`, `McpTester` | `ServerRequestFactoryInterface`, `ResponseFactoryInterface`, `StreamFactoryInterface` |
| URL OpenAPI spec | PSR-18 `ClientInterface`, PSR-17 `RequestFactoryInterface` |
| OpenAPI calls | `ClientInterface`, `RequestFactoryInterface`, `StreamFactoryInterface` |
| URL spec cache | PSR-16 `CacheInterface` при `cache_ttl > 0` |
| audit | `ClockInterface` там, где строится writer/interceptor |

`ServerRequestFactoryInterface` для `mcp:list` и `RequestFactoryInterface` для
OpenAPI — разные интерфейсы. Запускайте `mcp:doctor`: он выводит отсутствующий
FQCN адресно.

## Identity и два слоя авторизации

HTTP route защищают два независимых слоя:

1. `SharedSecretMiddleware` устанавливает machine/client identity.
2. Authentication middleware приложения заполняет `CurrentUser`; RBAC bridge
   принимает user authorization decision на каждый tool.

```php
// config/web/di.php
return [
    IdentitySourceInterface::class => CurrentUserIdentitySource::class,
];

// config/console/di.php: stdio не имеет HTTP CurrentUser
return [
    IdentitySourceInterface::class => static fn () => new StaticIdentitySource(
        getenv('MCP_USER_ID') ?: null,
    ),
];
```

`null` означает guest. Guest вместе с `RbacToolVisibility` может законно видеть
ноль tools; это не ошибка registry. Shared secret не является user identity и
не должен передаваться upstream.

## Порядок interceptors

```text
HTTP POST /rest/mcp
  └─ SharedSecretMiddleware
       └─ Authentication + CurrentUser
            └─ McpAction -> Server
                 ├─ SessionBudgetInterceptor    core, всегда outermost
                 ├─ SessionIdentityInterceptor
                 ├─ AuditTrailInterceptor
                 ├─ RbacToolCallInterceptor
                 ├─ TracingToolCallInterceptor
                 └─ MetricsToolCallInterceptor
                      └─ tool
```

```php
'interceptors' => [
    SessionIdentityInterceptor::class,
    AuditTrailInterceptor::class,
    RbacToolCallInterceptor::class,
    TracingToolCallInterceptor::class,
    MetricsToolCallInterceptor::class,
],
'tool_visibility' => RbacToolVisibility::class,
```

Фактические blind spots: budget rejection и session identity mismatch не
попадают в audit/tracing/metrics. RBAC rejection аудируется; tracing failure
тоже попадает в audit. Чтобы аудитировать все policy denials, core должен дать
настраиваемое placement/outcome hook для budget guard; configured interceptor
не может обернуть hard-coded outer guard.

## OpenAPI credentials и cache

Static `openapi.headers` — service-token mode. Upstream API не наследует MCP
caller/RBAC decision: не публикуйте tenant/user-scoped operation с более
широким service token.

Delegated mode задаёт вместе `identity_provider` и
`delegated_header_provider`. Provider вызывается на каждый call, получает
operation id/method/path и immutable `ExecutionIdentity`, но не raw MCP secret.
Ошибка provider fail-closed; входящий `Authorization` нельзя прокидывать
вслепую; credentials не должны попадать в audit, exceptions или cache keys.

URL spec можно кэшировать через PSR-16:

```php
'openapi' => [
    'spec_path' => 'https://api.example.test/openapi.json',
    'base_url' => 'https://api.example.test',
    'operations' => ['orderStatus'],
    'headers' => [],
    'cache_ttl' => 60,
    'identity_provider' => AppExecutionIdentityProvider::class,
    'delegated_header_provider' => AppDelegatedHeaderProvider::class,
],
```

Cache хранит raw document; allow-list/validation выполняются при каждой сборке.
Ошибка cache даёт HTTP fallback, HTTP failure остаётся fail-closed. Удалённая
operation может оставаться доступной до TTL; для security-sensitive spec
используйте local file или короткий TTL.

## OpenTelemetry и Fiber

Поддерживаемая automatic propagation: NTS PHP, `ext-ffi`,
`OTEL_PHP_FIBERS_ENABLED=true`; в FPM при необходимости preload
`vendor/autoload.php`. Проверка bridge:

```bash
MCP_OTEL_FIBER_TEST=1 vendor/bin/testo --suite=Integration
```

`Context::setStorage(new ContextStorage())` допустим только как ограниченный
fallback для строго последовательного php-fpm. Он небезопасен при
чередующихся Fiber/event loop. `fork()/switch()` только вокруг `$next()` не
решает lifecycle SDK Fiber.

## One-provider rule

Core не биндит сменный backend interface. Ровно один backend (`-db`, `-otel`,
`-prometheus`) или приложение связывает его в DI. Два backend-пакета для одного
interface дают осознанный `yiisoft/config Duplicate key`; vendor override layer
настраивается только root package.

## Проверка установки

```bash
./yii mcp:doctor
./yii mcp:doctor --probe
./yii mcp:list
./yii mcp:list --json > build/mcp-schema.json
```

`mcp:list` выводит counts для tools/resources/templates/prompts. JSON snapshot
храните в CI и diff-ите при изменении конфигурации. Минимальный protocol smoke
test должен пройти реальный путь:

```php
$tester = new McpTester($server, $serverRequestFactory, $responseFactory, $streamFactory);
$tools = $tester->listTools();
$result = $tester->callTool('health.status');
```

Для OTLP используйте Collector/Tempo/Jaeger и проверяйте span через backend
API/UI. HTTP 2xx exporter-а сам по себе не доказывает, что payload распознан.
