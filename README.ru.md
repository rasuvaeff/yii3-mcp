# rasuvaeff/yii3-mcp

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-mcp?label=stable&sort_semver=1)](https://packagist.org/packages/rasuvaeff/yii3-mcp)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-mcp)](https://packagist.org/packages/rasuvaeff/yii3-mcp)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-mcp/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-mcp/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-mcp/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-mcp/actions)
[![Psalm level](https://img.shields.io/badge/psalm-level%201-141F48?logo=psalm&logoColor=white)](https://github.com/rasuvaeff/yii3-mcp/blob/master/psalm.xml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-mcp/php)](https://packagist.org/packages/rasuvaeff/yii3-mcp)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-mcp)](LICENSE.md)

[English version](README.md)

Интеграция сервера [Model Context Protocol](https://modelcontextprotocol.io) с
Yii3 поверх **официального** [`mcp/sdk`](https://packagist.org/packages/mcp/sdk)
(PHP Foundation + Symfony). Пакет публикует операции предметной области
приложения как MCP tools/resources для AI-агентов (Claude Code, Claude Desktop
и других) через PSR-15 Streamable HTTP endpoint; инструменты разрешаются через
Yii3 DI-контейнер.

> Пользуетесь AI coding assistant? [llms.txt](llms.txt) содержит компактную
> API-справку, которую можно передать модели. Для контрибьюторов: [AGENTS.md](AGENTS.md).

## Требования

| Требование | Версия |
|------------|--------|
| PHP | 8.3 - 8.5 |
| `mcp/sdk` | `~0.6.0` (экспериментален до 1.0, поэтому используется tilde pin) |
| MCP protocol | 2025-06-18 (через SDK) |
| `ext-fileinfo` | требуется SDK |

## Установка

```bash
composer require rasuvaeff/yii3-mcp
```

## Использование

### 1. Объявите инструмент

Инструменты - обычные Yii3 services. Методы capabilities размечаются
собственными attributes SDK: пакет не создаёт собственных protocol structures.

```php
use Mcp\Capability\Attribute\McpTool;

final readonly class OrderTools
{
    public function __construct(private OrderRepository $orders) {}

    /**
     * Returns the current status of an order.
     */
    #[McpTool(name: 'order.status')]
    public function status(string $orderId): string
    {
        return $this->orders->get($orderId)->status->value;
    }
}
```

SDK строит input schemas из сигнатуры метода и DocBlock.
`#[McpResource]`, `#[McpResourceTemplate]` и `#[McpPrompt]` работают так же:
распознаются все четыре capability attributes SDK.

#### Структурированный вывод

Агенты гораздо надёжнее разбирают типизированный результат, чем текст.
Объявите `outputSchema` в attribute и верните массив: SDK публикует схему в
`tools/list` и помещает возвращённое значение в `structuredContent` вместе с
человекочитаемым текстом.

```php
/**
 * @return array{status: string, total: int}
 */
#[McpTool(
    name: 'order.status',
    outputSchema: [
        'type' => 'object',
        'properties' => [
            'status' => ['type' => 'string'],
            'total' => ['type' => 'integer'],
        ],
        'required' => ['status', 'total'],
    ],
)]
public function status(string $orderId): array
{
    $order = $this->orders->get($orderId);

    return ['status' => $order->status->value, 'total' => $order->total];
}
```

Массив или JSON-serializable object создаёт `structuredContent` и без
`outputSchema`; схема сообщает агенту форму результата заранее.
`Testing\SchemaSnapshot` покрывает output schemas так же, как input schemas,
поэтому случайное изменение контракта остановит build.

Чтобы включать capability class по условию (feature flag, проверка окружения),
реализуйте `ConditionalToolInterface`. Экземпляр будет разрешён контейнером
при построении сервера и пропущен, когда `shouldRegister()` возвращает `false`.

```php
final readonly class BetaTools implements ConditionalToolInterface
{
    public function __construct(private FeatureFlags $flags) {}

    public function shouldRegister(): bool
    {
        return $this->flags->isEnabled('mcp-beta-tools');
    }

    #[McpTool(name: 'beta.op')]
    public function betaOp(): string { ... }
}
```

### 2. Зарегистрируйте его

```php
// config/params.php
return [
    'rasuvaeff/yii3-mcp' => [
        'server_name' => 'my-app',
        'server_version' => '1.0.0',
        'tools' => [OrderTools::class],
        'endpoint_secret' => getenv('MCP_SECRET'),
    ],
];
```

Handlers регистрируются как references `[class, method]`. При вызове SDK
разрешает экземпляр через Yii3-контейнер, поэтому constructor dependencies
инъецируются обычным образом.

### 3. Добавьте маршрут endpoint

```php
// config/routes.php
Route::methods(['POST', 'GET', 'DELETE', 'OPTIONS'], '/mcp')
    ->middleware(SharedSecretMiddleware::class)
    ->action(McpAction::class),
```

MCP-клиент подключается с секретом в заголовке:

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

### stdio для локальной разработки

```php
// add McpServeCommand to your console commands
./yii mcp:serve
```

Конфигурация Claude Code: `claude mcp add my-app -- ./yii mcp:serve`.

### Интроспекция фактически доступных capabilities

`mcp:list` выводит каждый зарегистрированный tool, resource, resource template
и prompt с краткими сведениями об аргументах (`name*` означает обязательный)
без MCP-клиента. Команда проходит тот же in-process JSON-RPC путь, что и
реальный клиент, поэтому в неё попадают attribute tools, OpenAPI-операции и
Markdown prompts.

```php
// add McpListCommand to your console commands
./yii mcp:list
./yii mcp:list --json   # full definitions as normalized JSON
```

`--json` печатает полные definitions capabilities, включая input/output
schemas, в нормализованном формате `SchemaSnapshot`. Порядок элементов и
ключей стабилен, поэтому вывод хорошо подходит для CI diff и automation.
Как и `McpTester`, команде требуются PSR-17 factories
`ServerRequestFactoryInterface`, `ResponseFactoryInterface` и
`StreamFactoryInterface` в контейнере.

### Диагностика: mcp:doctor

`mcp:doctor` проверяет конфигурацию MCP-сервера end-to-end и выводит каждую
проверку как pass/skip/fail — секрет и значения настроенных headers никогда
не попадают в вывод:

```bash
./yii mcp:doctor           # человекочитаемая таблица
./yii mcp:doctor --json    # machine-readable отчёт
./yii mcp:doctor --probe   # разрешить сеть (загрузка URL OpenAPI spec)
```

Проверки в порядке диагностики: endpoint secret настроен, session directory
доступна на запись, session round-trip через настроенный store, OpenAPI spec
загружается, server build. Exit codes стабильны для скриптов: `0` — здоров,
`2` — config error, `3` — storage error, `4` — upstream error; берётся
категория **первой** упавшей проверки (проверки идут от корневых причин, так
что сломанный config отражается как config, хотя ломает и server build).

Без `--probe` команда не трогает сеть: при URL в `spec_path` и загрузка
спеки, и server build (который загружает её eagerly) отражаются как skipped.

### Sessions (важно для PHP-FPM)

MCP Streamable HTTP session охватывает несколько HTTP-запросов: сначала
`initialize`, затем `tools/call` с полученным `Mcp-Session-Id`. Стандартный
in-memory store SDK теряет session между FPM workers, поэтому пакет по
умолчанию использует **file-based store** (`sys_get_temp_dir()`, путь можно
переопределить параметром `session.dir`). В multi-host setup перебиндите
interface:

```php
// config/common/di/mcp.php
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Server\Session\SessionStoreInterface;

return [
    SessionStoreInterface::class => static fn (CacheInterface $cache) =>
        new Psr16SessionStore($cache),
];
```

### Prompts из Markdown-файлов

Prompts - это content, а не code: храните их в directory, и каждый `*.md`
файл станет MCP prompt. Их можно редактировать без deployment и версионировать
как остальные файлы.

```php
'rasuvaeff/yii3-mcp' => [
    'prompts_path' => __DIR__ . '/../resources/prompts',
],
```

```markdown
---
name: code-review          # defaults to the file name
title: Code review assistant
description: Reviews a diff with a given focus
arguments:
  - name: diff
    description: The diff to review
    required: true
  - focus                  # simple form: optional argument
---
Review the following diff focusing on {{focus}}:

{{diff}}
```

Объявленные placeholders `{{argument}}` подставляются из запроса; отсутствующие
становятся пустыми строками, необъявленные остаются без изменений. Некорректный
frontmatter, недоступный файл или duplicate prompt name завершают построение
сервера `Prompts\Exception\InvalidPromptFileException`, а не тихо скрывают
prompt.

> Формат намеренно совместим с
> [vjik/my-prompts-mcp](https://github.com/vjik/my-prompts-mcp) Сергея
> Предводителева и вдохновлён им: один prompt file работает и в личном stdio
> prompt manager, и на application server.

## Interceptors: обёртка каждого tools/call

`Interceptor\ToolCallInterceptorInterface` - публичная extension point пакета
вокруг выполнения tools. Цепочка оборачивает **все** пути регистрации:
attribute tools, OpenAPI operations и configurator-registered handlers.
Поэтому tracing, rate limiting и ACL реализуются в одном месте без изменения
самих tools.

```php
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;

final readonly class TracingInterceptor implements ToolCallInterceptorInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        // $context->toolName, $context->arguments, $context->session,
        // $context->getClientInfo() - who is calling what with which input
        $this->logger->info('tools/call', ['tool' => $context->toolName]);

        return $next();   // skip $next() to short-circuit
    }
}
```

```php
// config/params.php - resolved through the container, first = outermost
'rasuvaeff/yii3-mcp' => [
    'interceptors' => [TracingInterceptor::class],
],
```

Исключение `Mcp\Exception\ToolCallException` из interceptor отклоняет вызов
обычным MCP tool-error envelope, с объяснением для агента. Любое другое
исключение становится непрозрачной internal error.

### Маскирование чувствительных аргументов

Всё, что interceptor отправляет за пределы процесса - log line, trace span,
audit record - не должно содержать secrets. `Interceptor\ArgumentMasker`
заменяет значения чувствительных ключей (`password`, `secret`, `token`,
`api_key`, `credit_card` по умолчанию, без учёта регистра и на **каждом** уровне
вложенности) на `***`.

```php
use Rasuvaeff\Yii3Mcp\Interceptor\ArgumentMasker;

$masker = new ArgumentMasker();                       // or: new ArgumentMasker(['ssn', 'password'])
$safe = $masker->mask($context->arguments);
// ['user' => ['name' => 'alice', 'password' => '***']]

$this->logger->info('tools/call', ['tool' => $context->toolName, 'arguments' => $safe]);
```

Это единый helper, поэтому audit trail, telemetry и custom interceptors
маскируют данные одинаково и не расходятся по semantics.

### Session budget: остановка agent loops

Это жёсткий предел `tools/call` на MCP session - с `initialize` до истечения
TTL. Зациклившийся агент исчерпает budget и получит понятную tool error вместо
того, чтобы непрерывно нагружать приложение.

```php
'rasuvaeff/yii3-mcp' => [
    'session' => ['budget' => 50],   // 0 = unlimited (default)
],
```

Защита действует **внутри одной session**, а не задаёт client quota: повторный
`initialize` начинает новый counter. Client quotas должны жить в rate limiter
уровня приложения. Budget guard всегда внешний interceptor и отклоняет вызов
до работы остальных interceptors.

### Client identity и ротация секретов

Один endpoint может обслуживать несколько MCP-клиентов, каждый со своим
секретом — и у клиента может быть **несколько активных секретов** на время
ротации (добавьте новый, переключите клиентов, удалите старый; удалённый
секрет отзывается немедленно):

```php
'rasuvaeff/yii3-mcp' => [
    'client_secrets' => [
        'ci' => getenv('MCP_SECRET_CI'),
        'claude' => [getenv('MCP_SECRET_CLAUDE_OLD'), getenv('MCP_SECRET_CLAUDE_NEW')],
    ],
],
```

`SharedSecretMiddleware` резолвит предъявленный header через
`Identity\SecretResolverInterface` (каждое сравнение — `hash_equals()`,
constant-time) и передаёт дальше по pipeline **client id** — никогда не сам
секрет: interceptors видят его как `ToolCallContext::$clientId`, и он
зеркалируется в session для audit/telemetry-бриджей. Одиночный
`endpoint_secret` продолжает работать без изменений как клиент `default`;
обе формы сразу — fail-fast ошибка. На stdio (`mcp:serve`) HTTP-запроса нет,
поэтому `$clientId` равен `null`.

### Per-client rate limits (свой limiter)

Пакет сознательно не несёт собственного limiter-хранилища. Реализуйте
`Interceptor\ToolCallLimiterInterface` поверх rate limiter'а, который уже
работает в приложении (`yiisoft/rate-limiter`, Redis, …), и добавьте
`Interceptor\RateLimitInterceptor` в список `interceptors`:

```php
final readonly class AppToolCallLimiter implements ToolCallLimiterInterface
{
    public function __construct(private CounterInterface $counter) {}

    public function allow(string $clientId, string $toolName): bool
    {
        return $this->counter->hit($clientId . ':' . $toolName)->isAllowed();
    }
}

// params
'rasuvaeff/yii3-mcp' => [
    'interceptors' => [RateLimitInterceptor::class],
],
// di: bind ToolCallLimiterInterface => AppToolCallLimiter
```

Interceptor ключует вызовы по client id (fallback `anonymous` для
транспортов без identity) плюс имя tool'а — per-client и per-tool лимиты
задаются конфигурацией вашего limiter'а. **Fail-closed**: если limiter-бэкенд
бросает исключение, вызов отклоняется — enforced quota не должна молча
превращаться в «безлимит» при аварии.

### Видимость tools

`ConditionalToolInterface` управляет registration глобально при build time.
Чтобы скрыть часть зарегистрированных tools на endpoint, обычно достаточно
declarative tool-name patterns в params; `*` соответствует любой
последовательности символов.

```php
'rasuvaeff/yii3-mcp' => [
    'visibility' => [
        'deny' => ['admin.*'],        // hide matches
        'allow' => [],                // non-empty = hide everything it does not match
    ],
],
```

`deny` имеет приоритет над `allow`; пустые списки, значение по умолчанию,
делают видимым каждый tool. Когда решение зависит от **session** (admin/public
client, tenant plan), реализуйте `Visibility\ToolVisibilityInterface`: решение
выполняется для каждой session по handshake data.

```php
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;

final readonly class PlanBasedVisibility implements ToolVisibilityInterface
{
    public function isVisible(Tool $tool, ?SessionInterface $session): bool
    {
        // decide from $session->get('client_info'), tenant data, ...
        return !str_starts_with($tool->name, 'admin.') || $this->isAdmin($session);
    }
}
```

```php
'rasuvaeff/yii3-mcp' => [
    'tool_visibility' => PlanBasedVisibility::class,   // DI-resolved
],
```

Два вида конфигурации взаимоисключающие: оба одновременно дают build-time
error. В обоих случаях filter работает согласованно в двух точках:
`tools/list` исключает невидимые tools, а `tools/call` **fail-closed**
отклоняет их. Клиент, угадавший скрытое имя, получит tool error; вызов не
дойдёт ни до interceptor chain, ни до tool. Это ранний filter, а не замена ACL
уровня приложения.

### Server configurators

Кроме встроенных Markdown-prompts и OpenAPI bridge можно зарегистрировать
собственные реализации `ServerConfiguratorInterface` или companion package.
Они добавляют capabilities в SDK server builder до его построения. Core
разрешает FQCN через container после собственных configurators и применяет их
по порядку.

```php
'rasuvaeff/yii3-mcp' => [
    'configurators' => [MyServerConfigurator::class],   // DI-resolved
],
```

```php
final readonly class MyServerConfigurator implements ServerConfiguratorInterface
{
    #[\Override]
    public function configure(Builder $builder): void
    {
        // $builder->addTool(...) / addResource(...) / addPrompt(...) ...
    }
}
```

## Multi-tenant serving (rasuvaeff/yii3-tenancy)

С [rasuvaeff/yii3-tenancy](https://github.com/rasuvaeff/yii3-tenancy) MCP
endpoint обслуживает каждый tenant на одном route. Tools являются обычными
Yii3 services, поэтому инъецированный в constructor `CurrentTenant` ограничит
доступ к данным так же, как в любом другом месте приложения. Ключевой момент -
порядок middleware: tenant должен быть разрешён **до** запуска MCP action.

```php
// config/routes.php - secret first (fail-closed), then tenant, then MCP
Route::methods(['POST', 'GET', 'DELETE', 'OPTIONS'], '/mcp')
    ->middleware(SharedSecretMiddleware::class)
    ->middleware(TenantResolutionMiddleware::class)   // e.g. HeaderTenantResolver('X-Tenant-Id')
    ->action(McpAction::class),
```

```php
// an MCP client carries both headers
"headers": { "X-Mcp-Secret": "...", "X-Tenant-Id": "acme" }
```

Изолируйте sessions по tenant, чтобы session id никогда не пересекал tenants:
bind session store к directory конкретного tenant.

```php
// config/common/di/mcp.php
SessionStoreInterface::class => static fn (CurrentTenant $tenant) =>
    new FileSessionStore(
        directory: sys_get_temp_dir() . '/mcp-sessions/' . $tenant->get()->getId(),
    ),
```

Per-tenant tool sets уже поддерживаются `tool_visibility`: принимайте решение
по разрешённому tenant, а не по `client_info`.

> **Честный scope:** shared secret остаётся глобальным, поэтому любой, кто им
> владеет, может передать любой `X-Tenant-Id`. Это соответствует модели
> trusted-only endpoint: secret уже выдаёт доступ к приложению. Изоляция tenant
> защищает от ошибок, а не от malicious secret holder. Per-tenant secrets
> (secret resolver вместо middleware с одним значением) запланированы; сообщите,
> если они нужны.

## OpenAPI bridge: публикация существующего REST API

Если приложение уже поддерживает OpenAPI document, allow-listed operations
можно без дублирования опубликовать как MCP tools. Имя берётся из
`operationId`, description - из `summary`/`description`, input schemas - из
parameters/request body, output schemas - из success response (см. ниже).
Вызовы исполняются как настоящие HTTP requests к API
и проходят весь middleware stack (validation, rate limiting, auth), в отличие
от hand-written tools, вызывающих handlers напрямую.

```php
// config/params.php
'rasuvaeff/yii3-mcp' => [
    'openapi' => [
        // file path OR http(s) URL - e.g. the app's own spec endpoint,
        // always current; fetched with the same `headers` (auth included)
        'spec_path' => 'https://api.example.com/rest/json-url',
        'base_url' => 'https://api.example.com',
        'operations' => ['getBlogTags', 'getPage'],   // allow-list, empty = nothing
        'headers' => ['Authorization' => 'Bearer ' . getenv('MCP_API_TOKEN')],
        'safe_methods_only' => true,   // read-only bridge: non-GET in the list => build error
    ],
],
```

DI wiring требует PSR-18/PSR-17 services (`ClientInterface`,
`RequestFactoryInterface`, `StreamFactoryInterface`) в container. Request body
передаётся единым tool argument `body`; отсутствующий в document `operationId`
вызывает `UnknownOperationException` при server build. Не-GET operation при
`safe_methods_only` вызывает `UnsafeOperationException` (fail-fast). Локальные
`#/components/...` `$ref` разрешаются inline до 32 chained hops; external
(URL/file) `$ref` остаются неразрешёнными для request-body schemas. URL
parameters намеренно ограничены scalar schemas `string`, `integer`, `number`
и `boolean` со стандартной OpenAPI serialization (`simple` для path, `form`
для query). Header/cookie parameters, external или non-scalar parameter
schemas, custom serialization, non-default `explode` и `allowReserved=true`
приводят к `InvalidSpecException` при выборе operation. Фиксированные upstream
headers задаются через `headers`/`HttpOperationExecutor::defaultHeaders`; более
сложные контракты требуют custom tool. Дублирующиеся `operationId` также
отклоняются при индексировании document. Tool arguments индексируются по
имени: operation с path и query parameter одного имени, либо parameter `body`
одновременно с request body, не может быть bridged и бросает
`InvalidSpecException` при build time.

### Output schema из responses

Bridged tool также рекламирует `outputSchema` в `tools/list`, если operation
объявляет подходящий success response: **наименьший конкретный 2xx** с
`application/json` schema типа `object` (локальные `$ref` разрешаются,
top-level keywords канонизируются до `type`/`properties`/`required`/
`additionalProperties`/`description`). Агент видит форму ответа до вызова,
а MCP-клиенты валидируют возвращённый `structuredContent` по этой схеме.
Array/scalar responses и wildcard `2XX` не рекламируются - JSON object
payload всё равно приходит как `structuredContent`, просто без контракта.
Держите OpenAPI document честным: расхождение спеки с реальным API
проявится как ошибки валидации на стороне клиента.

Для custom scenarios используйте части напрямую: `SpecIndex` +
`HttpOperationExecutor` + `OpenApiServerConfigurator` (`ServerConfiguratorInterface`,
generic extension point для `McpServerFactory::create(tools, configurators)`).

## Компоненты

| Class | Роль |
|---|---|
| `McpServerFactory` | список tool FQCN -> настроенный SDK `Server`; читает attributes, подключает DI container и session store |
| `McpAction` | PSR-15 handler, запускающий SDK `StreamableHttpTransport` для текущего request |
| `SharedSecretMiddleware` | fail-closed `hash_equals()` guard; пустой secret отклоняет все requests с поясняющим 503; client id резолвится через `Identity\SecretResolverInterface` |
| `Identity\SecretResolverInterface` / `Identity\StaticSecretResolver` | несколько клиентов на endpoint + ротация секретов (несколько активных секретов на client id); constant-time сравнение, сырой секрет не уходит дальше middleware |
| `Interceptor\ToolCallLimiterInterface` / `Interceptor\RateLimitInterceptor` | порт + адаптер, делегирующие per-client/per-tool лимиты rate limiter'у приложения; fail-closed при аварии limiter'а |
| `McpServeCommand` | `mcp:serve`, stdio transport для локальных MCP clients |
| `McpListCommand` | `mcp:list`, консольная интроспекция tools/resources/prompts; `--json` для machine-readable definitions |
| `McpDoctorCommand` | `mcp:doctor` — health check конфигурации (secret, session storage, OpenAPI spec, server build) со стабильными exit codes (0/2/3/4 = healthy/config/storage/upstream); `--json`, `--probe` |
| `Doctor\McpDoctor` | сервис диагностики за `mcp:doctor`; возвращает immutable `DoctorReport` из `CheckResult` (`CheckStatus` pass/skip/fail, `CheckCategory` config/storage/upstream) |
| `Exception\InvalidToolClassException` | configured tool class отсутствует или не имеет capability attributes (fail-fast) |
| `ConditionalToolInterface` | capability class отказывается от registration при build time через `shouldRegister()` |
| `Testing\McpTester` | in-process test client: initialize/list всех paginated capabilities/callTool/readResource |
| `Testing\SchemaSnapshot` | contract canary: committed JSON snapshot всех capability schemas; drift ломает build |
| `Prompts\MarkdownPromptsConfigurator` | directory `*.md` files как MCP prompts, vjik/my-prompts-mcp-compatible format |
| `ServerConfiguratorInterface` | extension point для добавления capabilities в builder через params `configurators` |
| `Interceptor\ToolCallInterceptorInterface` | оборачивает каждый tools/call: tracing, ACL, rate limits; params `interceptors` |
| `Interceptor\ToolCallContext` | данные interceptor: tool name, arguments, session, `getClientInfo()` |
| `Interceptor\SessionBudgetInterceptor` | per-session tools/call cap: параметр `session.budget`, anti-loop guard |
| `Interceptor\InterceptingReferenceHandler` | decorator, подключающий chain к SDK; используется `McpServerFactory` |
| `Interceptor\ArgumentMasker` | единое sensitive-argument masking на каждом nesting level |
| `Visibility\ToolVisibilityInterface` | per-session tool filter: `tools/list` скрывает, `tools/call` fail-closed отклоняет |
| `Visibility\DeclarativeToolVisibility` | deny/allow patterns имён tools с wildcard `*`: параметр `visibility` |
| `OpenApi\OpenApiServerConfigurator` | публикует allow-listed OpenAPI operations как tools через HTTP |
| `OpenApi\Exception\*` | `InvalidSpecException`, `UnknownOperationException`, `UnsafeOperationException`, `OperationFailedException` |

## Безопасность

- **Endpoint только для доверенных клиентов.** MCP tools исполняют application
  code; относитесь к endpoint как к admin API. Поставляйте его за
  `SharedSecretMiddleware` (пустой secret отклоняет каждый request поясняющим
  503) или за явным network ACL.
- SDK возвращает tool errors как MCP error envelopes, поэтому internals не
  раскрываются в 500 traces.
- Core по умолчанию **не регистрирует tools**: каждая опубликованная operation
  является явной записью `params['rasuvaeff/yii3-mcp']['tools']`.
- OAuth из MCP authorization spec намеренно вне scope до стабилизации
  спецификации; используйте только shared-secret/ACL.

## Примеры

См. [examples/](examples/): каждый script запускается offline.

| Script | Что показывает | Нужен server? |
|--------|----------------|:-------------:|
| [`http-handshake.php`](examples/http-handshake.php) | Полный in-process MCP cycle: initialize + tools/call | нет |
| [`stdio-serve.php`](examples/stdio-serve.php) | stdio transport `mcp:serve` на in-memory streams | нет |
| [`conditional.php`](examples/conditional.php) | registration gating через `ConditionalToolInterface` | нет |
| [`prompts.php`](examples/prompts.php) | Markdown files как MCP prompts | нет |
| [`openapi-bridge.php`](examples/openapi-bridge.php) | OpenAPI operations, опубликованные как MCP tools | нет |
| [`interceptors.php`](examples/interceptors.php) | tracing interceptor с `ArgumentMasker` и session budget guard | нет |
| [`visibility.php`](examples/visibility.php) | per-session interface, declarative deny patterns и fail-closed call | нет |
| [`structured-output.php`](examples/structured-output.php) | `outputSchema` и `structuredContent` tool | нет |

## Тестирование своих tools

`Testing\McpTester` запускает реальный Streamable HTTP code path in-process:
без HTTP server и без stdio process.

```php
$tester = new McpTester($server, $psr17, $psr17, $psr17);

$result = $tester->callTool('order.status', ['orderId' => '42']);
$this->assertSame('paid', $result['content'][0]['text']);

$tester->listTools();                 // все paginated tool definitions
$tester->listResources();             // все resource definitions
$tester->listResourceTemplates();     // все resource-template definitions
$tester->listPrompts();               // все prompt definitions
$tester->readResource('app://x');     // resource contents
$tester->request('custom/method');     // любой raw JSON-RPC method
```

### Schema snapshot: защита от случайного изменения контракта

Изменение method signature без предупреждения меняет generated `inputSchema`
и ломает работающих agents. `Testing\SchemaSnapshot` снимает каждое served
capability definition в committed JSON file; изменение ломает test, пока
snapshot не будет намеренно пересоздан:

```php
SchemaSnapshot::verify($tester, __DIR__ . '/mcp-schema.json');
// a mismatch throws with a per-section summary:
// "tools: changed [order.status]; prompts: added [code-review]"
```

`verify()` считает **отсутствующий** snapshot ошибкой — удалённый или
незакоммиченный файл не даст зелёный CI. Для создания или намеренной
регенерации запустите тесты один раз с env-флагом (или вызовите
`SchemaSnapshot::record()`) и закоммитьте файл:

```bash
MCP_SNAPSHOT_RECORD=1 vendor/bin/testo --suite=Unit
```

`assert()` остаётся migration-friendly режимом: отсутствующий файл создаётся
на первом прогоне, дальше сравнение как в `verify()`.

При обновлении pin `mcp/sdk` ожидайте регенерацию: schema serialization может
корректно измениться между SDK minors.

Для interactive debugging используйте официальный MCP Inspector:

```bash
npx @modelcontextprotocol/inspector
# transport: Streamable HTTP, URL: https://your-app/rest/mcp,
# header: X-Mcp-Secret: <secret>
```

## Roadmap

Запланированное направление: tool-call interceptors, AI audit trail, session
budgets, tenant-scoped serving и per-session tool visibility. См. [ROADMAP.md](ROADMAP.md).

## Разработка

На хосте нет PHP/Composer: запускайте их в Docker через образ `composer:2`.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Или используйте Make: `make build`, `make cs-fix`, `make psalm`, `make test`.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
