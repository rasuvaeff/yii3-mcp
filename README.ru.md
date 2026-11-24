# rasuvaeff/yii3-mcp

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-mcp?label=stable&sort_semver=1)](https://packagist.org/packages/rasuvaeff/yii3-mcp)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-mcp)](https://packagist.org/packages/rasuvaeff/yii3-mcp)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-mcp/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-mcp/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-mcp/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-mcp/actions)
[![Psalm level](https://img.shields.io/badge/psalm-level%201-141F48?logo=psalm&logoColor=white)](https://github.com/rasuvaeff/yii3-mcp/blob/master/psalm.xml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-mcp/php)](https://packagist.org/packages/rasuvaeff/yii3-mcp)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-mcp)](LICENSE.md)
[![Docs](https://img.shields.io/github/actions/workflow/status/rasuvaeff/mcp/docs.yml?branch=master&label=docs)](https://rasuvaeff.github.io/mcp/)

[English version](README.md)

**[Документация](https://rasuvaeff.github.io/mcp/)** — полное руководство, API-справочник и cookbook находятся в `rasuvaeff/mcp`. Сайт документации англоязычный.

Интеграция сервера [Model Context Protocol](https://modelcontextprotocol.io) с
Yii3 поверх **официального** [`mcp/sdk`](https://packagist.org/packages/mcp/sdk)
(PHP Foundation + Symfony). Пакет публикует операции предметной области
приложения как MCP tools/resources для AI-агентов (Claude Code, Claude Desktop
и других) через PSR-15 Streamable HTTP endpoint; инструменты разрешаются через
Yii3 DI-контейнер.

Сам runtime находится в [`rasuvaeff/mcp`](https://github.com/rasuvaeff/mcp) и ничего не знает о Yii;
OpenAPI bridge — в [`rasuvaeff/mcp-openapi`](https://github.com/rasuvaeff/mcp-openapi). Этот пакет добавляет
сторону Yii3: подключение через `config-plugin`, params и `mcp:doctor`. Обновление с 5.x: [UPGRADE.md](UPGRADE.md).

> Пользуетесь AI coding assistant? [llms.txt](llms.txt) содержит компактную
> API-справку, которую можно передать модели. Для контрибьюторов: [AGENTS.md](AGENTS.md).
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills)
> дополнительно получают agent-скилл этого пакета в `.agents/skills/`
> автоматически при установке.

## Требования

| Требование | Версия |
|------------|--------|
| PHP | 8.3 - 8.6 |
| `mcp/sdk` | `~0.10.0` (экспериментальный до 1.0 — отсюда tilde-пин) |
| `rasuvaeff/mcp`, `rasuvaeff/mcp-openapi` | `^0.1` (ставится автоматически) |
| `firebase/php-jwt` | необязательно, `^7.0`: проверка JWT для OAuth resource server |
| MCP protocol | 2024-11-05 … 2025-11-25 через `initialize` (согласуется), 2026-07-28 stateless |
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
use Mcp\Server\Capability\Attribute\McpTool;

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

Клиенты, которые аутентифицируются по OAuth 2.1, вместо этого ставят
`BearerTokenMiddleware` перед action — см.
[OAuth 2.1 resource server](https://rasuvaeff.github.io/mcp/oauth).

### stdio для локальной разработки

```bash
./yii mcp:serve
```

Конфигурация Claude Code: `claude mcp add my-app -- ./yii mcp:serve`.

Команды `mcp:serve`, `mcp:list` и `mcp:doctor` регистрируются через params
пакета для `yiisoft/yii-console`, поэтому появляются в `./yii list` сразу
после `composer require` — без конфигурации на стороне приложения.
Приложение со своим map'ом commands мержится с ним рекурсивно (слой
приложения выигрывает при конфликте имён — команду можно переименовать
или перевязать, повторно объявив её имя); приложение без `yiisoft/config`
может, как раньше, перечислить три класса в своих console params.

### Проверьте, что отдаёт сервер

```bash
./yii mcp:list             # все tools, resources и prompts, без клиента
./yii mcp:doctor           # проверка конфигурации со стабильными exit codes
```

`mcp:doctor` — это `McpDoctorCommand` над `Doctor\McpDoctor`, который возвращает
`DoctorReport` из `CheckResult` (`CheckStatus`, `CheckCategory`). См.
[Operations](https://rasuvaeff.github.io/mcp/operations).

## Конфигурация

Всё находится в `params['rasuvaeff/yii3-mcp']`; каждый ключ описан в `config/params.php`.
Как собирается сервер, объясняет [Yii3: configuration](https://rasuvaeff.github.io/mcp/yii3/configuration).

| Params | Раздел |
|---|---|
| `server_name`, `server_version`, `instructions`, `pagination_limit`, `protocol_version`, `result_json` | [имя сервера и общие настройки](https://rasuvaeff.github.io/mcp/yii3/configuration#server-wide-knobs) |
| `tools`, `configurators` | [классы tools и свои configurators](https://rasuvaeff.github.io/mcp/capabilities) |
| `endpoint_secret`, `client_secrets`, `secret_header` | [секреты, несколько клиентов, ротация](https://rasuvaeff.github.io/mcp/security) |
| `oauth` | [OAuth 2.1 resource server](https://rasuvaeff.github.io/mcp/oauth) |
| `allowed_hosts`, `expected_http_host`, `session` | [хосты и хранение sessions](https://rasuvaeff.github.io/mcp/security#sessions-on-disk) |
| `modern_era`, `header_validation`, `cache_policy`, `request_state` | [stateless-эра](https://rasuvaeff.github.io/mcp/protocol#protocol-revisions) |
| `notifications` | [уведомления об обновлении ресурсов](https://rasuvaeff.github.io/mcp/capabilities#subscriptions) |
| `interceptors`, `prompt_interceptors`, `resource_interceptors`, `result_decorators` | [цепочки interceptors и decorators](https://rasuvaeff.github.io/mcp/interceptors) |
| `tool_call_budget`, `limits`, `cache` | [бюджет вызовов, лимит размера, кэш результатов](https://rasuvaeff.github.io/mcp/interceptors#result-size-limit-and-caching) |
| `tool_visibility`, `prompt_visibility`, `resource_visibility`, `visibility` | [что видит session](https://rasuvaeff.github.io/mcp/visibility) |
| `openapi` | [публикация существующего REST API](https://rasuvaeff.github.io/mcp/openapi-bridge) |
| `apps` | [MCP Apps](https://rasuvaeff.github.io/mcp/apps) |

## Bridges

[Audit log](https://github.com/rasuvaeff/yii3-mcp-audit-log-bridge),
[RBAC](https://github.com/rasuvaeff/yii3-mcp-rbac-bridge) и
[telemetry](https://github.com/rasuvaeff/yii3-mcp-telemetry-bridge) — обычные
interceptors: см. [Bridges](https://rasuvaeff.github.io/mcp/bridges/overview). Для tenants —
[Multi-tenant serving](https://rasuvaeff.github.io/mcp/multi-tenant), для тестов —
[Testing your tools](https://rasuvaeff.github.io/mcp/testing).

## Безопасность

- **Endpoint только для доверенных клиентов.** MCP tools исполняют application
  code; относитесь к endpoint как к admin API. Поставляйте его за
  `SharedSecretMiddleware` или `OAuth\BearerTokenMiddleware` (пустой secret
  отклоняет каждый request поясняющим 503) или за явным network ACL.
- SDK возвращает tool errors как MCP error envelopes, поэтому internals не
  раскрываются в 500 traces.
- Core по умолчанию **не регистрирует tools**: каждая опубликованная operation
  является явной записью `params['rasuvaeff/yii3-mcp']['tools']`.
- **Sessions привязаны к создавшему их клиенту** (неизменяемый владелец
  ставится при `initialize` и проверяется перед каждым POST/DELETE, а за ним —
  собственная привязка SDK к principal):
  утёкший `Mcp-Session-Id` сам по себе не даёт другому аутентифицированному
  клиенту действовать в чужой session или уничтожить её. Session files —
  owner-only (`0700` dir, `0600` files) в application-specific directory.
- **Уникальность имён capabilities по всему серверу enforced.** Коллизия
  между любыми путями регистрации (attribute tools, configurators, OpenAPI
  bridge) проваливает build с
  `DuplicateCapabilityException` — никогда не тихий last-write-wins SDK,
  после которого правила visibility/cache/RBAC/audit описывают исчезнувший
  handler.
- Весь вывод, зависящий от caller-а, ограничен по размеру **до** аллокации:
  upstream response bodies (`openapi.max_response_bytes`, инкрементальное
  чтение), spec documents (10 MiB + бюджет
  глубины/узлов `$ref`) и tool results (`limits.tool_result_bytes`).
- **OAuth 2.1** через `OAuth\BearerTokenMiddleware` (части resource server
  из SDK): токен, audience, issuer и скоупы проверяются до запуска
  transport; см. [OAuth 2.1 resource server](https://rasuvaeff.github.io/mcp/oauth).
- Вложенные аргументы tools маскируются на любой глубине, включая объекты
  (`ArgumentMasker`), а `requestState` многораундового вызова привязан к
  своему запросу и клиенту.

## Примеры

[`yii3-wiring.php`](examples/yii3-wiring.php) собирает конфигурацию пакета настоящим
контейнером `yiisoft/di`. Скрипты runtime и bridge — в
[`rasuvaeff/mcp`](https://github.com/rasuvaeff/mcp) (runtime) и
[`rasuvaeff/mcp-openapi`](https://github.com/rasuvaeff/mcp-openapi) (OpenAPI bridge); см. [examples/](examples/).

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
