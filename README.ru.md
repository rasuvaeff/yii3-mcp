# rasuvaeff/yii3-mcp

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-mcp?label=stable&sort_semver=1)](https://packagist.org/packages/rasuvaeff/yii3-mcp)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-mcp)](https://packagist.org/packages/rasuvaeff/yii3-mcp)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-mcp/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-mcp/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-mcp/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-mcp/actions)
[![Psalm level](https://img.shields.io/badge/psalm-level%201-141F48?logo=psalm&logoColor=white)](https://github.com/rasuvaeff/yii3-mcp/blob/master/psalm.xml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-mcp/php)](https://packagist.org/packages/rasuvaeff/yii3-mcp)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-mcp)](LICENSE.md)
[![Docs](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-mcp/docs.yml?branch=master&label=docs)](https://rasuvaeff.github.io/yii3-mcp/)

[English version](README.md)

**[Документация](https://rasuvaeff.github.io/yii3-mcp/)** — полное руководство, сгенерированный API-справочник по всем четырём пакетам и cookbook. Сайт документации англоязычный.

Интеграция сервера [Model Context Protocol](https://modelcontextprotocol.io) с
Yii3 поверх **официального** [`mcp/sdk`](https://packagist.org/packages/mcp/sdk)
(PHP Foundation + Symfony). Пакет публикует операции предметной области
приложения как MCP tools/resources для AI-агентов (Claude Code, Claude Desktop
и других) через PSR-15 Streamable HTTP endpoint; инструменты разрешаются через
Yii3 DI-контейнер.

> Пользуетесь AI coding assistant? [llms.txt](llms.txt) содержит компактную
> API-справку, которую можно передать модели. Для контрибьюторов: [AGENTS.md](AGENTS.md).
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills)
> дополнительно получают agent-скилл этого пакета в `.agents/skills/`
> автоматически при установке.

## Требования

| Требование | Версия |
|------------|--------|
| PHP | 8.3 - 8.5 |
| `mcp/sdk` | `~0.7.0` (экспериментален до 1.0, поэтому используется tilde pin) |
| MCP protocol | 2025-11-25 (дефолт SDK; он анонсирует эту ревизию в `initialize` независимо от того, что запросил клиент) |
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

#### Подсказки о поведении tool

Используйте `ToolAnnotations` из SDK напрямую, когда клиенту нужно знать,
читает ли tool данные, меняет ли состояние или обращается за пределы своего
закрытого домена. Специальные attributes yii3-mcp не нужны:

```php
use Mcp\Schema\ToolAnnotations;

#[McpTool(
    name: 'order.cancel',
    annotations: new ToolAnnotations(
        readOnlyHint: false,
        destructiveHint: true,
        idempotentHint: true,
        openWorldHint: false,
    ),
)]
public function cancel(string $orderId): string
{
    $this->orders->cancel($orderId);

    return 'cancelled';
}
```

| Hint | Значение |
|---|---|
| `readOnlyHint` | `true`, если tool не меняет своё окружение |
| `destructiveHint` | для изменяющего состояния tool отличает разрушительные изменения от только добавляющих |
| `idempotentHint` | для изменяющего состояния tool сообщает, что повторный вызов с теми же аргументами не добавляет эффекта |
| `openWorldHint` | `true`, если tool может взаимодействовать с внешними сущностями за пределами закрытого домена |

Annotations — рекомендательные MCP metadata. Клиент может их проигнорировать,
поэтому они не заменяют authorization, validation, `safe_methods_only`,
visibility или server-side confirmation. В частности, одного
`idempotentHint` недостаточно для автоматических retries: серверный allow-list
повторяемых tools должен оставаться явным.

#### Server-initiated communication

Attribute tool может принять request-scoped `RequestContext` из SDK как
параметр. SDK создаёт его для текущего MCP request и исключает из генерируемой
input schema, поэтому клиент передаёт только настоящие domain arguments. Через
его `ClientGateway` tool отправляет progress/log notifications или инициирует
sampling и elicitation:

```php
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Server\RequestContext;

#[McpTool(
    name: 'release.deploy',
    annotations: new ToolAnnotations(
        readOnlyHint: false,
        destructiveHint: true,
        idempotentHint: false,
        openWorldHint: false,
    ),
)]
public function deploy(string $version, RequestContext $context): string
{
    $client = $context->getClientGateway();
    $client->progress(progress: 1, total: 2, message: 'Validation complete');

    if (!$client->supportsElicitation()) {
        throw new RuntimeException('Client does not support required deployment confirmation');
    }

    $confirmation = $client->elicit(
        message: sprintf('Deploy version %s?', $version),
        requestedSchema: new ElicitationSchema(
            properties: [
                'confirmed' => new BooleanSchemaDefinition(
                    title: 'Confirm deployment',
                    description: 'Allow this deployment to proceed',
                ),
            ],
            required: ['confirmed'],
        ),
    );

    if (!$confirmation->isAccepted() || ($confirmation->content['confirmed'] ?? false) !== true) {
        throw new RuntimeException('Deployment was not confirmed');
    }

    return sprintf('Deployment %s queued', $version);
}
```

`progress()` ничего не делает, если caller не передал progress token. `log()`
отправляет видимые клиенту log notifications; `sample()` и `elicit()` — это
round trips, которые приостанавливают Fiber tool до ответа клиента или
истечения timeout SDK. Проверяйте `supportsElicitation()` перед обязательным
elicitation, выбирайте fail-closed fallback для разрушительных операций и не
предполагайте, что каждый MCP client поддерживает все server-initiated
capabilities. Передавайте `RequestContext` в метод, а не в constructor: он
принадлежит одному request/session.

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

Листинг — это **default (unauthenticated) view**: команда работает через
синтетическую session без client identity и сообщает об этом в выводе. С
per-session visibility или RBAC реальный клиент может видеть другой набор
capabilities.
Как и `McpTester`, команде требуются PSR-17 factories. Эти services должны быть
во всех config groups, где строится `Mcp\Server`, включая console:

| Entry point / feature | Обязательные services |
|---|---|
| `McpAction` | `ResponseFactoryInterface`, `StreamFactoryInterface` |
| `McpListCommand`, `McpTester` | `ServerRequestFactoryInterface`, `ResponseFactoryInterface`, `StreamFactoryInterface` |
| URL OpenAPI spec | PSR-18 `ClientInterface`, PSR-17 `RequestFactoryInterface` |
| OpenAPI operation execution | PSR-18 `ClientInterface`, PSR-17 `RequestFactoryInterface`, `StreamFactoryInterface` |

`ServerRequestFactoryInterface` и `RequestFactoryInterface` — разные PSR-17
контракты; binding одного не заменяет другой.

### Диагностика: mcp:doctor

`mcp:doctor` проверяет конфигурацию MCP-сервера end-to-end и выводит каждую
проверку как pass/skip/fail — настроенный секрет и значения headers никогда
не попадают в вывод, а из печатаемых URL вырезаются userinfo credentials
(сообщения исключений application services проходят с усечением — считайте
отчёт диагностикой для оператора):

```bash
./yii mcp:doctor           # человекочитаемая таблица
./yii mcp:doctor --json    # machine-readable отчёт
./yii mcp:doctor --probe   # разрешить сеть (загрузка URL OpenAPI spec)
```

Проверки охватывают endpoint secret, optional `expected_http_host` allow-list,
точные PSR services для включённых entry points/features, session storage
(включая **конфиденциальность**: session directory, читаемая group/others,
проваливает проверку — не только незаписываемая), OpenAPI spec, конфигурацию
MCP Apps (каждое декларативное определение парсится, поэтому битое будет
показано даже когда проверка server build пропущена) и реальный server build. Отсутствующий service выводится с точным
именем interface. Exit codes стабильны для скриптов: `0` — здоров,
`2` — config error, `3` — storage error, `4` — upstream error; берётся
категория **первой** упавшей проверки (проверки идут от корневых причин, так
что сломанный config отражается как config, хотя ломает и server build).

Без `--probe` команда не трогает сеть: при URL в `spec_path` и загрузка
спеки, и server build (который загружает её eagerly) отражаются как skipped.

### Sessions (важно для PHP-FPM)

MCP Streamable HTTP session охватывает несколько HTTP-запросов: сначала
`initialize`, затем `tools/call` с полученным `Mcp-Session-Id`. Стандартный
in-memory store SDK теряет session между FPM workers, поэтому пакет по
умолчанию использует **file-based store** — поставляемый
`Session\PrivateFileSessionStore` делает его owner-only: directory создаётся
с mode `0700` (application-specific default под `sys_get_temp_dir()`,
выводится из `server_name`; переопределяется `session.dir`), а каждый
session file зажимается до `0600` — session JSON содержит client metadata и
всё нужное для replay session id. В multi-host setup перебиндите
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

#### Session привязана к создавшему её клиенту

SDK сам проверяет лишь существование предъявленного `Mcp-Session-Id` — иначе
любой аутентифицированный клиент мог бы действовать внутри чужой session
(или удалить её `DELETE`-ом), просто повторив её id, который утекает в
логи proxy/клиентов через HTTP header. Когда настроены `client_secrets`
(или `endpoint_secret`), `McpAction` записывает resolved client id в session
как **неизменяемого владельца при `initialize`** и проверяет его на каждом
`POST`/`DELETE` до запуска transport. Чужая — или бесхозная — session
получает тот же 404, что и отсутствующая: неотличимо от истёкшей.
Развёртывания без client identity (только network ACL) не затронуты.

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

Подстановка усиливает входные данные caller-а — значение аргумента
вставляется в КАЖДОЕ вхождение placeholder — поэтому развёрнутый prompt
ограничен параметром `limits.prompt_result_bytes` (по умолчанию 1 MiB,
`0` = без лимита). Размер считается арифметически и проверяется **до**
построения подставленной строки: превысивший бюджет `prompts/get` падает,
не выполняя аллокацию, которую отклоняет.

> Формат намеренно совместим с
> [vjik/my-prompts-mcp](https://github.com/vjik/my-prompts-mcp) Сергея
> Предводителева и вдохновлён им: один prompt file работает и в личном stdio
> prompt manager, и на application server.

### Автодополнение аргументов (`completion/complete`)

Клиент подсказывает значения, пока пользователь набирает аргумент промпта или
переменную resource-template. Источник объявляется SDK-атрибутом
`#[CompletionProvider]` — никакого API yii3-mcp здесь не участвует:

```php
use Mcp\Capability\Attribute\CompletionProvider;

#[McpPrompt(name: 'review')]
public function review(
    #[CompletionProvider(values: ['security', 'performance'])] string $focus,
    #[CompletionProvider(enum: Environment::class)] string $environment,
): string { /* … */ }

#[McpResourceTemplate(uriTemplate: 'app://reports/{region}', name: 'report')]
public function report(
    #[CompletionProvider(provider: RegionCompletionProvider::class)] string $region,
): string { /* … */ }
```

| Форма | Источник |
|---|---|
| `values: [...]` | фиксированный список, матчится по префиксу |
| `enum: BackedEnum::class` | кейсы enum |
| `provider: Foo::class` | `Mcp\Capability\Completion\ProviderInterface`, **резолвится через DI-контейнер** — можно ходить в репозиторий или сервис фича-флагов |

На аргумент — ровно одна из трёх форм; capability анонсируется автоматически.
Completions подчиняются `prompt_visibility` / `resource_visibility`: промпт или
шаблон, которого сессия не видит, не дополняет ничего и отвечает «не найдено»,
неотличимо от отсутствующего (см. [Видимость tools](#видимость-tools)).
Interceptors `completion/complete` **не** оборачивают — это lookup метаданных, а
не вызов capability, поэтому авторизацию класть в visibility-фильтр, а не в
interceptor.

### Общие настройки сервера

```php
'rasuvaeff/yii3-mcp' => [
    // свободный текст «как пользоваться этим сервером» в результате initialize —
    // агент читает его до первого вызова. Пусто = не отдаётся.
    'instructions' => 'Prefer order.status over reading app://orders/{id}.',
    // размер страницы для списков tools/resources/templates/prompts.
    // Применяется и к обработчикам SDK, и к фильтрующим обработчикам пакета —
    // они не могут разойтись в пагинации из-за наличия visibility.
    'pagination_limit' => 50,
    // фиксирует ревизию, анонсируемую в initialize; пусто — дефолт SDK
    // (2025-11-25). Неподдерживаемое значение падает на загрузке конфига.
    'protocol_version' => '',
],
```

### Подписки на ресурсы

SDK анонсирует `resources.subscribe`, как только у сервера есть хоть один
ресурс, и записывает `resources/subscribe` в сессию. Сам по себе
`notifications/resources/updated` никто не шлёт — но тулза, которая *вызвала*
изменение, может сделать это в рамках того же запроса через
`Resource\ResourceUpdateNotifier`:

```php
public function __construct(private ResourceUpdateNotifier $notifier) {}

#[McpTool(name: 'order.cancel')]
public function cancel(string $orderId, RequestContext $context): string
{
    $this->orders->cancel($orderId);
    $this->notifier->notify($context, 'app://orders/' . $orderId);

    return 'cancelled';
}
```

`notify()` возвращает, был ли вызывающий подписан; сессии, которая не
подписывалась, не отправляется ничего — незапрошенное уведомление на провод не
попадёт. Свой `Mcp\Server\Resource\SubscriptionManagerInterface` подхватят
обе стороны — и обработчик subscribe, и нотификатор; по умолчанию биндится
session-backed менеджер SDK.

**Достижима только вызывающая сессия.** Другие сессии, подписанные на тот же
URI, — нет: для этого нужно соединение, которого у процесса нет. Под PHP-FPM
ничего не переживает запрос, поэтому внеполосный push по-прежнему невозможен —
клиентам, которым нужно видеть чужие изменения, остаётся опрос.

## Framework-agnostic usage

Несмотря на название пакета, код не имеет ни одной `yiisoft/*` runtime
dependency - `require` в `composer.json` это PSR-интерфейсы
(`psr/container`, `psr/http-message`, `psr/http-server-middleware`,
`psr/http-factory`, `psr/simple-cache`, `psr/log`) плюс `mcp/sdk` и
`symfony/console`/`yaml`. `McpServerFactory` принимает обычный
`Psr\Container\ContainerInterface`; `McpAction` и `SharedSecretMiddleware` -
plain PSR-15. `config/di.php` + `config/params.php` - это только
convenience-слой `yiisoft/config-plugin`; вне Yii3 те же классы собираются
руками с любым PSR-11 container и router, которые уже использует приложение
(Laravel, Symfony, Mezzio, Slim, …):

```php
use Mcp\Server\Session\FileSessionStore;
use Rasuvaeff\Yii3Mcp\{McpAction, McpServerFactory, SharedSecretMiddleware};

// любой PSR-11 container - Yii3, PHP-DI, Laravel, рукописный
$container = /* ... */;

$sessionStore = new FileSessionStore(directory: sys_get_temp_dir() . '/mcp-sessions', ttl: 3600);
$factory = new McpServerFactory(container: $container, sessionStore: $sessionStore, name: 'my-app', version: '1.0.0');
$server = $factory->create([OrderTools::class]);

$psr17 = /* любая PSR-17 factory, например nyholm/psr7 или guzzlehttp/psr7 */;
$action = new McpAction(server: $server, responseFactory: $psr17, streamFactory: $psr17);
$middleware = new SharedSecretMiddleware(secret: getenv('MCP_SECRET'), responseFactory: $psr17);

// маршрутизировать POST/GET/DELETE/OPTIONS /mcp через $middleware -> $action
// в том виде middleware-диспетчеризации, который ожидает router фреймворка
```

`McpServeCommand` (stdio) наследует
`Symfony\Component\Console\Command\Command` напрямую и не требует Yii3
console app - добавьте его в любой `Symfony\Component\Console\Application`.
Что теряется без `yiisoft/config`: автоматический array-merge между
пакетами (interceptors, visibility, OpenAPI bridge) - конструируйте
`Interceptor\*`/`Visibility\*`/`OpenApi\OpenApiServerConfigurator` напрямую и
передавайте их в `McpServerFactory::create()` - те же объекты, которые
`config/di.php` строит из params, просто без сборки через config-plugin.

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
заменяет значения чувствительных ключей (`password`/`pass`/`pwd`, `secret`,
`token`/`bearer`/`jwt`, `auth`/`authorization`, `cookie`, `api_key`/`apikey`/
`api-key`/`x-api-key`, `access_token`/`refresh_token`/`id_token`/
`session_token`/`auth_token` (и написание через дефис `access-token`),
`client_secret`, `private_key`, `credit_card` по умолчанию, без учёта
регистра - `ApiKey`/`X-Api-Key` тоже матчатся - и на **каждом** уровне
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

### Лимит размера результата и кеш

У tool result нет естественного верхнего предела - bridged GET к реальному
API или hand-written tool над большой таблицей может вернуть мегабайты JSON
и выжечь context window агента. `limits.tool_result_bytes` обрезает
превышающий лимит string result явным маркером; любой другой result
(array, object) вместо этого отклоняется - обрезанный JSON payload это
невалидный JSON, а не меньший валидный:

```php
'rasuvaeff/yii3-mcp' => [
    'limits' => ['tool_result_bytes' => 0],   // 0 = unlimited (default)
],
```

Лимит - байтовый бюджет содержимого: многобайтовый символ, не влезающий
целиком, отбрасывается, а не разрезается (разорванная UTF-8 последовательность
делает JSON-RPC ответ незакодируемым, а Streamable HTTP transport такой ответ
молча выбрасывает), сам маркер добавляется сверх бюджета. Маркер сообщает
реально сохранённое число байт.
Для string result бюджет считает байты **сырой** строки, до JSON encoding
(control characters расширяются на wire, до ~6x); для arrays/objects —
JSON-encoded байты. Он ограничивает то, что попадает в context window
агента; память worker-а при ПРОИЗВОДСТВЕ результата ограничивается отдельно
через `openapi.max_response_bytes`.

Для read-heavy tools, вызываемых повторно с теми же аргументами внутри
session (справочник, OpenAPI GET), `cache.tools` полностью пропускает
handler на hit - opt-in по имени tool (для OpenAPI bridge - **served**
имя, после возможного `tool_names` rename) с TTL в секундах:

```php
'rasuvaeff/yii3-mcp' => [
    'cache' => [
        'tools' => ['blog_tags_list' => 60],
    ],
],
```

Требует PSR-16 `CacheInterface` в контейнере. Cache key всегда включает
resolved client id - общий cache между разными клиентами утёк бы результат
одного клиента другому. Key material типизирован: отсутствующий client id
(stdio) кодируется как `null` и никогда не коллизирует с реальным клиентом,
названным `anonymous`. Key также несёт обязательный **namespace** (параметр
`cache.namespace`, по умолчанию `server_name`): два приложения на общем
cache backend — типичный общий Redis — с одинаково названными tools не
должны читать результаты друг друга, а внешний PSR-16 prefix — это defence
in depth, но не основная изоляция. Если настроен `openapi.identity_provider`, resolved
`ExecutionIdentity` тоже входит в key: delegated upstream credentials
означают, что тот же tool с теми же аргументами может давать
identity-specific результаты, а identity может быть мельче, чем client id
(много конечных пользователей за одним MCP client). Кешируются только
успешные results; брошенное exception никогда не кешируется. Ошибка
чтения/записи cache fails **open** (tool исполняется) - это оптимизация
availability, а не security gate. Ошибка identity **provider**, напротив,
fails **closed** для кешируемых tools: отдать результат, не зная чей он, -
ровно та утечка, ради предотвращения которой key и существует.

Ключ идентифицирует **MCP-клиента**, а не прикладного пользователя за ним.
Tool, результат которого зависит от того, кто залогинен (текущий пользователь
берётся из приложения, а не из MCP-identity), кешировать нельзя, пока
`openapi.identity_provider` не резолвит этого пользователя - «идемпотентное
чтение» не то же самое, что «одинаковый ответ для всех». Bridged operations
закрыты identity provider'ом; hand-written tools - на вашей ответственности.

Порядок interceptors фиксирован: session budget (самый внешний) →
настроенные `interceptors` → caching → result size limit (самый внутренний,
ближе всего к реальному вызову tool). Настроенные interceptors (RBAC,
audit) выполняются всегда, даже на cache hit - через cache нельзя обойти
их проверку. Size limit выполняется только на cache miss; в cache попадает
уже ограниченное значение, так что hit никогда не требует повторного
ограничения.

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
записывается в session как её неизменяемый владелец — для audit/telemetry
бриджей и проверки владения session. Одиночный
`endpoint_secret` продолжает работать без изменений как клиент `default`;
обе формы сразу — fail-fast ошибка, как и секрет, общий для двух разных
client id: резолюция возвращает первое совпадение, и дубликат молча
приписал бы вызовы одного клиента другому. На stdio (`mcp:serve`)
HTTP-запроса нет, поэтому `$clientId` равен `null`.

### Per-client rate limits (свой limiter)

Пакет сознательно не несёт собственного limiter-хранилища. Реализуйте
`Interceptor\ToolCallLimiterInterface` поверх rate limiter'а, который уже
работает в приложении (`yiisoft/rate-limiter`, Redis, …), и добавьте
`Interceptor\RateLimitInterceptor` в список `interceptors`:

```php
final readonly class AppToolCallLimiter implements ToolCallLimiterInterface
{
    public function __construct(private CounterInterface $counter) {}

    public function allow(?string $clientId, string $toolName): bool
    {
        return $this->counter->hit(($clientId ?? 'no-client') . ':' . $toolName)->isAllowed();
    }
}

// params
'rasuvaeff/yii3-mcp' => [
    'interceptors' => [RateLimitInterceptor::class],
],
// di: bind ToolCallLimiterInterface => AppToolCallLimiter
```

Interceptor ключует вызовы по client id плюс имя tool'а — per-client и
per-tool лимиты задаются конфигурацией вашего limiter'а. Транспорт без
identity (stdio) передаёт `null` — типизированное отсутствие, а не
зарезервированная строка, с которой мог бы коллизировать реальный client
id; как бакетировать анонимные вызовы, решает limiter. **Fail-closed**:
если limiter-бэкенд
бросает исключение, вызов отклоняется — enforced quota не должна молча
превращаться в «безлимит» при аварии.

### Retry транзиентных ошибок (свой retry)

Пакет не несёт retry-логики - наивный blanket retry дублирует side effects
у не-idempotent tool (двойное списание платежа, повторная отправка формы).
Ограничьте retry явным allow-list проверенно-idempotent tools и только
transient failure типами, используя
[`rasuvaeff/retry`](https://github.com/rasuvaeff/retry):

```php
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\OperationFailedException;

final readonly class RetryInterceptor implements ToolCallInterceptorInterface
{
    /** @param list<string> $idempotentTools проверенно idempotent - никогда не blanket-retry */
    public function __construct(private array $idempotentTools) {}

    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        if (!in_array($context->toolName, $this->idempotentTools, true)) {
            return $next();
        }

        return Retry::new()
            ->maxAttempts(3)
            ->withExponential(baseMs: 100, multiplier: 2.0, capMs: 2_000)
            ->retryOn(OperationFailedException::class)   // только transient failures
            ->run($next);
    }
}
```

Разместите его ближе к концу вашего списка `interceptors` (ближе к вызову
tool) - любой interceptor, стоящий перед ним (например `RateLimitInterceptor`),
оборачивает весь retry-loop целиком и проверяется один раз на внешний вызов,
а не на каждую попытку; если поставить его после - он будет срабатывать на
каждый retry.

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

Префикс `tag:` matches по тегам tool вместо имени - OpenAPI bridge
прокидывает OpenAPI `tags` в `_meta` tool, так что `'deny' => ['tag:admin']`
скрывает каждую bridged operation с тегом `admin` независимо от её
`operationId`/`tool_names` имени. Tool без тегов никогда не матчится
`tag:`-паттерном.

Важно, откуда берётся тег: из OpenAPI-документа. При URL-спеке
(`openapi.spec_path` на http(s)) документ, из которого пропал тег `admin`,
обезоруживает правило `deny: ['tag:admin']` - экспозиция по-прежнему
ограничена вашим allow-list'ом `operations`, но само правило deny надёжно
ровно настолько, насколько надёжен источник спеки. Для deny-правил поверх
удалённой спеки предпочитайте паттерны по имени, а `tag:` оставляйте для
allow-list'а и локальных файлов спеки.

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

### Hooks для prompts и resources

Те же точки расширения есть у остальных capabilities. `prompts/get` и
`resources/read` (static resources и templates одинаково) имеют собственные
interceptor chain и visibility filter — отдельные интерфейсы, чтобы tool-policy
случайно не применилась к prompt:

```php
// config/params.php — каждый список разрешается через container, первый = внешний
'rasuvaeff/yii3-mcp' => [
    'prompt_interceptors' => [PromptAuditInterceptor::class],     // Interceptor\PromptGetInterceptorInterface
    'resource_interceptors' => [ResourceAclInterceptor::class],   // Interceptor\ResourceReadInterceptorInterface
    'prompt_visibility' => PlanBasedPromptVisibility::class,      // Visibility\PromptVisibilityInterface
    'resource_visibility' => PlanBasedResourceVisibility::class,  // Visibility\ResourceVisibilityInterface
],
```

- `Interceptor\PromptGetContext` несёт имя prompt, arguments, session и
  client id; `Interceptor\ResourceReadContext` — URI, RFC 6570 variables из
  template (с совпавшим `uriTemplate`) и те же identity-поля.
- Отклонение: бросьте `Mcp\Exception\PromptGetException` /
  `Mcp\Exception\ResourceReadException` — клиент увидит сообщение.
  Скрытие: visibility (или брошенное `*NotFoundException`) отвечает
  **not found**, неотличимо от несуществующей capability — клиент,
  угадавший скрытое имя prompt или URI, не узнаёт ничего, а вызов не
  доходит ни до interceptors, ни до handler.
- Visibility фильтрует `prompts/list`, `resources/list` и
  `resources/templates/list` той же реализацией, что охраняет прямые
  вызовы, — листинг и чтение не могут разойтись.
- **`completion/complete` подчиняется тем же фильтрам.** Автодополнение
  аргументов (`#[CompletionProvider]` на аргументе промпта или переменной
  resource-template) SDK отдаёт напрямую из реестра, поэтому раньше оно
  отвечало и по промптам/шаблонам, которых сессия не видит, — утекали и
  подсказываемые значения, и сам факт существования capability. Теперь оно
  обёрнуто настроенной prompt/resource visibility и отвечает на скрытый ref
  «не найдено», ровно как на отсутствующий.

Для бриджей (audit, telemetry) ядро несёт единый словарь исходов —
`Interceptor\CallOutcome` (`success` / `rejected` / `error`,
`CallOutcome::fromThrowable()`): отказ rate limit или ACL классифицируется
как `rejected` и не загрязняет error-rate метрики.

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

Если приложение уже поддерживает OpenAPI document версии 3.0.x или 3.1.x,
allow-listed operations можно без дублирования опубликовать как MCP tools.
Имя берётся из `operationId` (или из `tool_names`, см. ниже), description -
из `summary`/`description`, input schemas - из parameters/request body,
output schemas - из success response (см. ниже).
Вызовы исполняются как настоящие HTTP requests к API
и проходят весь middleware stack (validation, rate limiting, auth), в отличие
от hand-written tools, вызывающих handlers напрямую.

```php
// config/params.php
'rasuvaeff/yii3-mcp' => [
    'openapi' => [
        // file path OR http(s) URL - e.g. the app's own spec endpoint,
        // always current; загружается со `spec_headers`, НЕ с `headers`
        'spec_path' => 'https://api.example.com/rest/json-url',
        'base_url' => 'https://api.example.com',
        'operations' => ['getBlogTags', 'getPage'],   // allow-list, empty = nothing
        // переименовать некрасивый сгенерированный operationId в
        // LLM-дружелюбное имя tool; немаппленные operations сохраняют operationId
        'tool_names' => ['getBlogTags' => 'blog_tags_list'],
        // credentials для operation calls, уходят только на base_url
        'headers' => ['Authorization' => 'Bearer ' . getenv('MCP_API_TOKEN')],
        // credentials для загрузки спеки, уходят только на spec_path (по умолчанию пусто)
        'spec_headers' => [],
        'cache_ttl' => 60,             // PSR-16 cache URL-спеки; 0 = загружать при каждом build
        'safe_methods_only' => true,   // read-only bridge: non-GET in the list => build error
        'max_response_bytes' => 4_194_304, // потолок upstream body, читается инкрементально
        'opaque_errors' => false,      // true = скрывать upstream error bodies
    ],
],
```

Скоупы credentials разделены намеренно: `headers` аутентифицирует operation
calls к `base_url`, `spec_headers` — загрузку спеки с `spec_path`. Когда они
указывают на разные origins, общий набор headers отдал бы API token хосту
спеки. Spec URL со встроенными credentials (userinfo) отклоняется сразу:
такой URL попадает в диагностику и сообщения исключений.

`tool_names` переименовывает только то, что MCP-клиенты видят как имя tool -
allow-list, исполнение handler'а и delegated-header вызовы остаются
индексированы по `operationId`. Interceptors, visibility rules и любой
audit/RBAC bridge должны ссылаться на **переименованное** имя. `operationId`
в `tool_names`, отсутствующий в `operations`, бросает `InvalidArgumentException`
при build time (вероятная опечатка); переименование, невалидное как имя MCP
tool или коллидирующее с именем другого tool, бросает `InvalidSpecException`.
Проверка коллизий охватывает и attribute tools: имена методов `#[McpTool]`,
зарегистрированных на том же сервере, зарезервированы, поэтому переименование
bridged operation в одно из них - ошибка при build time, а не bridged tool,
который молча не доезжает до `tools/list` (registry SDK работает по принципу
last-write-wins и регистрирует attribute tools последними).

Каждая `GET` operation рекламируется с `readOnlyHint: true` - конфигурация не
нужна. OpenAPI `tags` operation прокидываются в `_meta` served tool
(`{"rasuvaeff/yii3-mcp": {"tags": [...]}}`), которую declarative `tag:`
visibility pattern (см. [Видимость tools](#видимость-tools)) читает напрямую.

DI wiring требует PSR-18/PSR-17 services (`ClientInterface`,
`RequestFactoryInterface`, `StreamFactoryInterface`) и PSR-16 `CacheInterface`
при `cache_ttl > 0`. Cache хранит raw document; allow-list и validation
применяются при каждом server build. Ошибка cache даёт fallback на HTTP, но
ошибка HTTP/spec остаётся fail-closed. Удалённая operation может оставаться
доступной до TTL; для security-sensitive spec используйте local file или
короткий TTL. Request body
передаётся единым tool argument `body`; отсутствующий в document `operationId`
вызывает `UnknownOperationException` при server build. Не-GET operation при
`safe_methods_only` вызывает `UnsafeOperationException` (fail-fast). Локальные
`#/components/...` `$ref` разрешаются inline до 32 chained hops; external
(URL/file) `$ref` остаются неразрешёнными для request-body schemas. URL
parameters намеренно ограничены scalar schemas `string`, `integer`, `number`
и `boolean` со стандартной OpenAPI serialization (`simple` для path, `form`
для query). Header/cookie parameters, external или non-scalar parameter
schemas, custom serialization, non-default `explode` и `allowReserved=true`
приводят к `InvalidSpecException` при выборе operation. Path argument
отклоняется при вызове, если он пустой или `.`, либо содержит `..`, `/` или
`\` - `rawurlencode` оставляет точки как есть, а `/` кодирует в `%2F`,
который upstream, декодирующий до нормализации пути (Apache с
`AllowEncodedSlashes`, часть прокси и servlet-контейнеров), возвращает
настоящим разделителем; значение вида `../..` позволило бы выйти за пределы
allow-listed route - с credentials бриджа; пустое значение - тот же escape на
уровень выше (`/users/` - обычно collection route, а не allow-listed item
route). Одиночные точки допустимы (`v1.2` - валидный slug); значение, которому
нужен слеш или `..` внутри, через path argument не пробросить. Base URL не должен содержать credentials (userinfo),
query string или fragment - dry-run preview возвращает полный URL
вызывающему, поэтому base URL никогда не может быть носителем credentials.
Фиксированные upstream
headers задаются через `headers`/`HttpOperationExecutor::defaultHeaders`.
**Bridged operations исполняются с настроенными upstream credentials. Upstream
API автоматически не наследует identity MCP caller или RBAC decision. Не
публикуйте user/tenant-scoped operations с более широким service token.**

Для delegated authorization настройте вместе `identity_provider` и
`delegated_header_provider`. Первый возвращает immutable `ExecutionIdentity`,
второй на каждом call обменивает её на headers и получает только operation
id/method/path и identity, но никогда raw MCP shared secret. Не прокидывайте
входящий `Authorization` вслепую. Ошибка provider останавливает вызов до HTTP
(fail-closed), dynamic headers перекрывают одноимённые static headers и не
переиспользуются между calls. Дублирующиеся `operationId` также
отклоняются при индексировании document. Tool arguments индексируются по
имени: operation с path и query parameter одного имени, либо parameter `body`
одновременно с request body, не может быть bridged и бросает
`InvalidSpecException` при build time.

Лимиты ресурсов применяются **до** аллокации, а не после: upstream response
body читается инкрементально, и вызов падает в момент пересечения
`max_response_bytes` (заявленный `Content-Length` сверх лимита отклоняется
вообще без чтения); JSON decoding ограничен по глубине; сам OpenAPI document
ограничен по размеру (10 MiB) для URL и file источников, а inlining `$ref`
работает под явным бюджетом глубины + числа узлов — враждебная или
дегенеративная удалённая спека не заставит индексирование рекурсировать или
аллоцировать без предела. Upstream error bodies попадают в tool error
ограниченным UTF-8-безопасным фрагментом — либо полностью скрываются через
`opaque_errors`, когда детали ошибок upstream не предназначены MCP caller-у.

`operationId`, не пригодный как имя MCP tool (пробел, unicode, длиннее
64 символов — `^[A-Za-z0-9._/-]{1,64}$`), бросает `InvalidSpecException` при
выборе operation; сам `mcp/sdk` в этом случае только пишет warning в лог и
всё равно регистрирует tool — проблема проявится только как непрозрачный
отказ всего `tools/list` на стороне клиента. `null`-аргумент path/query
parameter трактуется как отсутствующий (пропускается, а не отправляется
пустым значением) — это соответствует nullable union нотации OpenAPI 3.1
(`{"type": ["string", "null"]}`) для scalar parameter schemas, которую bridge
принимает наравне с обычной 3.0 type-строкой.

### Output schema из responses

Bridged tool также рекламирует `outputSchema` в `tools/list`, если operation
объявляет подходящий success response: **наименьший конкретный 2xx** с
`application/json` schema типа `object` - nullable union нотация OpenAPI 3.1
(`type: ["object", "null"]`) принимается так же (локальные `$ref`
разрешаются, top-level keywords канонизируются до `type`/`properties`/
`required`/`additionalProperties`/`description`, всегда до простого типа
`"object"`). Агент видит форму ответа до вызова,
а MCP-клиенты валидируют возвращённый `structuredContent` по этой схеме.
Array/scalar responses и wildcard `2XX` не рекламируются - JSON object
payload всё равно приходит как `structuredContent`, просто без контракта.
Держите OpenAPI document честным: расхождение спеки с реальным API
проявится как ошибки валидации на стороне клиента.

Для custom scenarios используйте части напрямую: `SpecIndex` +
`HttpOperationExecutor` + `OpenApiServerConfigurator` (`ServerConfiguratorInterface`,
generic extension point для `McpServerFactory::create(tools, configurators)`).

### Кастомизация на уровне operation

`OperationModifierInterface` - hook на уровне отдельной operation,
применяется после `tool_names` rename - для изменения description,
добавления annotations или дальнейшего переименования без написания
целого `ServerConfiguratorInterface`:

```php
'rasuvaeff/yii3-mcp' => [
    'openapi' => [
        'operation_modifier' => MyOperationModifier::class,   // DI-resolved
    ],
],
```

```php
use Mcp\Schema\Tool;
use Rasuvaeff\Yii3Mcp\OpenApi\Operation;
use Rasuvaeff\Yii3Mcp\OpenApi\OperationModifierInterface;

final readonly class MyOperationModifier implements OperationModifierInterface
{
    public function modify(Operation $operation, Tool $tool): Tool
    {
        return new Tool(
            name: $tool->name,
            title: $tool->title,
            inputSchema: $tool->inputSchema,
            description: $tool->description . ' (read-only bridge)',
            annotations: $tool->annotations,
            outputSchema: $tool->outputSchema,
        );
    }
}
```

Смена имени из modifier валидируется и проверяется на коллизии так же, как
`tool_names` rename - fail-closed, как и везде в bridge.

### Dry-run: preview call без выполнения

```php
'rasuvaeff/yii3-mcp' => [
    'openapi' => [
        // operationId, для которых добавляется extra `dryRun` boolean argument
        'dry_run' => ['createSubscriber'],
    ],
],
```

У dry-run-enabled operation `inputSchema` получает extra argument
`dryRun: boolean`. Call tool с `dryRun: true` возвращает request, который
*был бы* отправлен (`operationId`, `method`, `url`, `body`) как text - никогда
как `structuredContent`, поэтому никогда не конфликтует с объявленным
`outputSchema` operation - без реальной отправки и без утечки upstream
credentials из процесса (headers никогда не попадают в preview). Флаг
проверяется дважды, fail-closed: operationId, отсутствующий в `dry_run`,
полностью игнорирует argument `dryRun` и всегда выполняется по-настоящему,
даже если client всё равно его прислал. На dry-run-enabled operation
non-boolean значение `dryRun` (`1`, `"true"`) отклоняется с ошибкой, а не
выполняется - malformed флаг никогда не должен превратить задуманный
preview в реальный call.

Dry-run ортогонален `safe_methods_only`: он не экспонирует operation, которую
safety gate иначе бы отверг - write operation всё так же требует
`safe_methods_only: false` (или отсутствия параметра), чтобы вообще быть
exposed. Dry-run call всё так же проходит через весь interceptor chain
(session budget, RBAC/audit, caching, size limit), как и любой другой call -
preview write action требует того же permission, что и реальный call.

## MCP Apps: интерактивный UI прямо в разговоре

[MCP Apps](https://github.com/modelcontextprotocol/ext-apps)
(`io.modelcontextprotocol/ui`) - HTML-документы, отдаваемые как `ui://`
ресурсы; клиент рендерит их в sandboxed iframe внутри разговора. Extension
обязан быть анонсирован в handshake: `ui://` ресурс на сервере, который его не
анонсирует, для клиента - просто текст.

```php
'rasuvaeff/yii3-mcp' => [
    'apps' => [
        // анонсировать extension (достаточно для attribute-based apps)
        'enable' => true,
        // декларативные приложения - без PHP-класса
        'definitions' => [
            [
                'uri' => 'ui://dashboard',        // required, должен начинаться с ui://
                'name' => 'dashboard',            // required, уникальное
                'html' => '<!DOCTYPE html>…',     // string или Closure(): string
                'title' => 'Dashboard',
                'description' => 'Sales overview',
                'csp' => ['connect_domains' => ['api.example.com']],
                'permissions' => ['geolocation' => true],
                'prefers_border' => true,
            ],
        ],
    ],
],
```

Непустой `definitions` включает extension сам по себе. `html` в виде
`Closure(): string` вычисляется на **каждом** `resources/read` - это хук для
шаблонизации и данных из DI, и именно поэтому тяжёлый рендер стоит столько на
каждое чтение.

### Attribute-based приложения

Для приложения с логикой объявляйте `ui://` ресурс обычным способом и
возвращайте контент сами:

```php
#[McpResource(
    uri: 'ui://report',
    name: 'report',
    mimeType: McpApps::MIME_TYPE,
    meta: ['ui' => new \stdClass()],        // marker на дескрипторе
)]
public function report(): TextResourceContents
{
    return new TextResourceContents(
        uri: 'ui://report',
        mimeType: McpApps::MIME_TYPE,
        text: '<!DOCTYPE html><h1>Report</h1>',
        meta: ['ui' => new UiResourceContentMeta(  // sandbox-контракт
            csp: new UiResourceCsp(connectDomains: ['api.example.com']),
            prefersBorder: true,
        )],
    );
}
```

Этому пути всё равно нужен `'apps' => ['enable' => true]` - именно он
анонсирует extension. Вернуть простую строку тоже можно, но нести `_meta.ui`
способен только возвращённый `TextResourceContents`.

### Куда какой `_meta.ui`

| Уровень | Значение | Что несёт |
|---|---|---|
| Дескриптор (`resources/list`) | `McpApps::resourceMarker()` - пустой `{}` | «этот ресурс - приложение», и больше ничего |
| Контент (`resources/read`) | `UiResourceContentMeta` | `csp`, `permissions`, `domain`, `prefersBorder` |

Перепутать их - единственная лёгкая ошибка здесь: политика sandbox на
дескрипторе игнорируется, а marker на контенте не говорит хосту ничего.

### Sandbox: CSP и permissions

`UiResourceCsp` задаёт allow-list того, куда iframe может обращаться
(`connect_domains` для fetch/XHR/WebSocket, `resource_domains` для
изображений/скриптов/стилей, `frame_domains`, `base_uri_domains`); отсутствие
CSP оставляет в силе собственный restrictive default хоста.
`UiResourcePermissions` запрашивает sandbox-возможности (`camera`,
`microphone`, `geolocation`, `clipboard_write`) - в params это обычные
boolean, и отправляются только те, что `true`.

Домены передаются клиенту как есть: политику применяет хост, а `definitions` -
конфигурация приложения, а не клиентский ввод. HTML приложения отдаётся без
изменений - не подставлять в него недоверенные данные ваша ответственность.

### Связь тулзы с приложением

```php
#[McpTool(
    name: 'refresh_report',
    meta: ['ui' => new UiToolMeta(resourceUri: 'ui://report')],
)]
public function refresh(): string { /* … */ }
```

`UiToolMeta::$visibility` (`ToolVisibility::Model` / `ToolVisibility::App`)
объявляет, кто может её вызывать: app-only тулза скрывается из `tools/list`
модели **хостом**; сервер лишь заявляет намерение, поэтому это не граница
контроля доступа. Гарантия на стороне сервера - `Visibility\ToolVisibilityInterface`,
который fail-closed отвергает сам вызов.

В остальном app-ресурсы - обычные ресурсы: их фильтрует
`ResourceVisibilityInterface`, их чтения оборачивают `resource_interceptors`,
а `ui://` URI, столкнувшийся с attribute-ресурсом, роняет сборку как любой
другой дубликат. `McpAppsConfigurator` - единственный, кто включает extension:
второй `enableExtension(new McpApps())` из конфигуратора приложения уронит
сборку (SDK отвергает дублирующийся extension id).

## Компоненты

| Class | Роль |
|---|---|
| `McpServerFactory` | список tool FQCN -> настроенный SDK `Server`; читает attributes, подключает DI container и session store |
| `McpAction` | PSR-15 handler, запускающий SDK `StreamableHttpTransport` для текущего request; ставит неизменяемого владельца session при `initialize` и отвечает 404 на чужой session POST/DELETE |
| `Session\PrivateFileSessionStore` | owner-only file session store: directory создаётся `0700`, session files зажимаются до `0600` (поставляемый default) |
| `Exception\SessionOwnershipException` | capability call пришёл с client identity, отличной от неизменяемого владельца session (fail-closed) |
| `Exception\DuplicateCapabilityException` | две регистрации capability разрешились в одну identity — build падает вместо тихого last-write-wins SDK |
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
| `Interceptor\ResponseSizeLimitInterceptor` | ограничивает размер tool result (параметр `limits.tool_result_bytes`) - обрезает strings, отклоняет oversized arrays/objects |
| `Interceptor\CachingToolCallInterceptor` | PSR-16 cache успешных tool results, по имени tool с TTL (параметр `cache.tools`); типизированный ключ включает обязательный application namespace, client id и, при delegated auth, `ExecutionIdentity` |
| `Interceptor\InterceptingReferenceHandler` | decorator, подключающий chain к SDK; используется `McpServerFactory` |
| `Interceptor\ArgumentMasker` | единое sensitive-argument masking на каждом nesting level |
| `Visibility\ToolVisibilityInterface` | per-session tool filter: `tools/list` скрывает, `tools/call` fail-closed отклоняет |
| `Visibility\DeclarativeToolVisibility` | deny/allow patterns имён tools с wildcard `*`: параметр `visibility` |
| `Interceptor\PromptGetInterceptorInterface` / `Interceptor\ResourceReadInterceptorInterface` | обёртка каждого prompts/get и resources/read (params `prompt_interceptors` / `resource_interceptors`) с `PromptGetContext` / `ResourceReadContext` |
| `Visibility\PromptVisibilityInterface` / `Visibility\ResourceVisibilityInterface` | per-session фильтры prompts/resources (params `prompt_visibility` / `resource_visibility`): списки скрывают, прямой get/read отвечает not-found |
| `Interceptor\CallOutcome` | единый словарь `success`/`rejected`/`error` для audit/telemetry бриджей (`fromThrowable()`) |
| `OpenApi\OpenApiServerConfigurator` | публикует allow-listed OpenAPI operations как tools через HTTP |
| `OpenApi\OperationModifierInterface` | hook кастомизации на уровне operation, применяется после `tool_names` rename |
| `OpenApi\Operation` | read-only контекст operation, передаваемый в `OperationModifierInterface::modify()` |
| `OpenApi\Exception\*` | `InvalidSpecException`, `UnknownOperationException`, `UnsafeOperationException`, `OperationFailedException` |
| `Resource\ResourceUpdateNotifier` | шлёт `notifications/resources/updated` вызывающей сессии изнутри запроса, изменившего ресурс; неподписанной сессии не отправляется ничего |
| `Apps\McpAppsConfigurator` | анонсирует extension MCP Apps и регистрирует декларативные `ui://` app-ресурсы (params `apps`) |
| `Apps\AppDefinition` | одно декларативное приложение: `ui://` URI, имя, HTML (строка или `Closure(): string`) и его `UiResourceContentMeta` |

## Безопасность

- **Endpoint только для доверенных клиентов.** MCP tools исполняют application
  code; относитесь к endpoint как к admin API. Поставляйте его за
  `SharedSecretMiddleware` (пустой secret отклоняет каждый request поясняющим
  503) или за явным network ACL.
- SDK возвращает tool errors как MCP error envelopes, поэтому internals не
  раскрываются в 500 traces.
- Core по умолчанию **не регистрирует tools**: каждая опубликованная operation
  является явной записью `params['rasuvaeff/yii3-mcp']['tools']`.
- **Sessions привязаны к создавшему их клиенту** (неизменяемый владелец
  ставится при `initialize` и проверяется перед каждым POST/DELETE):
  утёкший `Mcp-Session-Id` сам по себе не даёт другому аутентифицированному
  клиенту действовать в чужой session или уничтожить её. Session files —
  owner-only (`0700` dir, `0600` files) в application-specific directory.
- **Уникальность имён capabilities по всему серверу enforced.** Коллизия
  между любыми путями регистрации (attribute tools, configurators, OpenAPI
  bridge, Markdown prompts) проваливает build с
  `DuplicateCapabilityException` — никогда не тихий last-write-wins SDK,
  после которого правила visibility/cache/RBAC/audit описывают исчезнувший
  handler.
- Весь вывод, зависящий от caller-а, ограничен по размеру **до** аллокации:
  upstream response bodies (`openapi.max_response_bytes`, инкрементальное
  чтение), подставленные prompts (`limits.prompt_result_bytes`,
  арифметическая пре-проверка), spec documents (10 MiB + бюджет
  глубины/узлов `$ref`) и tool results (`limits.tool_result_bytes`).
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
| [`openapi-bridge.php`](examples/openapi-bridge.php) | OpenAPI operations, опубликованные как MCP tools, с `tool_names` и `OperationModifierInterface` | нет |
| [`interceptors.php`](examples/interceptors.php) | tracing interceptor с `ArgumentMasker`, session budget guard и result size limit | нет |
| [`visibility.php`](examples/visibility.php) | per-session interface, declarative deny patterns и fail-closed call | нет |
| [`structured-output.php`](examples/structured-output.php) | `outputSchema` и `structuredContent` tool | нет |
| [`server-initiated.php`](examples/server-initiated.php) | официальный `ToolAnnotations` и schema-safe параметр `RequestContext` для progress/elicitation | нет |
| [`completions.php`](examples/completions.php) | `completion/complete`: список значений / enum / провайдер из контейнера, и visibility поверх автодополнения | нет |
| [`mcp-apps.php`](examples/mcp-apps.php) | MCP Apps: декларативные и attribute-based `ui://` приложения, размещение `_meta.ui`, CSP/permissions, тулза, связанная с приложением | нет |

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
