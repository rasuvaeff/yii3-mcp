---
title: "Architecture"
---

# Architecture

## McpServerFactory: from tool classes to an SDK Server

`McpServerFactory::create()` (`src/McpServerFactory.php`) is the single place
that turns application config into an `Mcp\Server\Builder`-built `Server`:

1. For every FQCN in `tools`, it reflects the class's public methods for the
   SDK's own attributes (`#[McpTool]`, `#[McpResource]`,
   `#[McpResourceTemplate]`, `#[McpPrompt]`) and registers each as a
   `[class, method]` handler via `Builder::addTool()` / `addResource()` /
   `addResourceTemplate()` / `addPrompt()`. A class implementing
   `ConditionalToolInterface` is resolved through the container first and
   skipped when `shouldRegister()` returns `false`. A class with **no**
   capability attributes on any public method throws
   `InvalidToolClassException` at build time — never a silently empty
   registration.
2. Every `ServerConfiguratorInterface` (the Markdown-prompts configurator, the
   OpenAPI bridge, your own) runs next, each contributing capabilities to the
   same builder. A configurator implementing
   `ReservedToolNamesAwareInterface` is handed the attribute tools' names
   first, so it can fail fast on a collision instead of silently losing a
   tool to the SDK's last-write-wins registry (see below).
3. If any tool/prompt/resource interceptor or visibility filter is
   configured, the builder's reference handler is swapped for
   `Interceptor\InterceptingReferenceHandler` — a decorator wrapping the
   SDK's own `ReferenceHandler`, so **every** registration path (attribute
   tools, OpenAPI-bridged operations, configurator-added handlers) goes
   through the same chain. With nothing configured, the SDK's reference
   handler is used unmodified — zero overhead.
4. The registry is always wrapped in `GuardedRegistry` (`@internal`): the SDK
   registry is last-write-wins with no duplicate check, so a name collision
   between *any* two registration paths would otherwise silently drop one
   handler. `GuardedRegistry` throws `Exception\DuplicateCapabilityException`
   at build time on any live duplicate — this is the second, structural line
   of defense behind the reserved-names handshake in step 2.
5. If any per-session visibility filter is configured, the corresponding
   `Filtered*Handler` (`Visibility\FilteredListToolsHandler`,
   `FilteredListPromptsHandler`, `FilteredListResourcesHandler`,
   `FilteredListResourceTemplatesHandler`, and
   `FilteredCompletionCompleteHandler` when prompt or resource visibility is
   set) is registered as a request handler ahead of the SDK's own — see
   [Visibility](/visibility) for why `completion/complete` needs its own
   decorator.

## Wiring: config/di.php + config/params.php

Everything above is driven from one params namespace,
`params['rasuvaeff/yii3-mcp']`, read once in `config/di.php` when the `Server`
service is built:

- `SessionStoreInterface` is bound to `Session\PrivateFileSessionStore`
  (owner-only, FPM-safe) by default — see [Security](/security#sessions-on-disk).
- `McpServerFactory` receives `server_name`, `server_version`,
  `instructions`, `pagination_limit`, and a `protocol_version` resolved
  **at config-load time**: an unsupported value throws immediately, not on
  the first request.
- The `Server` definition closure assembles, in order: the Markdown-prompts
  configurator (if `prompts_path` is set), the OpenAPI bridge configurator
  (if `openapi.spec_path` and `openapi.operations` are both non-empty), the
  MCP Apps configurator (if `apps.enable` or `apps.definitions` is set), then
  every FQCN in `configurators`; the interceptor list (session budget →
  `interceptors` → caching → size limit, see [Interceptors](/interceptors));
  and the visibility bindings (`tool_visibility` or declarative
  `visibility`, `prompt_visibility`, `resource_visibility`).
- `McpAction` receives the built `Server`, PSR-17 factories, `allowed_hosts`
  (for the transport's DNS-rebinding protection), and the same
  `SessionStoreInterface` — required for session-ownership enforcement (see
  [Security](/security#session-ownership)).
- `SharedSecretMiddleware` receives `endpoint_secret` (or a
  `StaticSecretResolver` built from `client_secrets`) and `secret_header`.
- `McpDoctor` (behind `mcp:doctor`) receives enough of the same config to
  diagnose it independently — see [Operations](/operations).

Everything is resolved through the container **inside** these closures
(`$container->get($interceptorClass)`), not eagerly at config-parse time —
so an interceptor, visibility class, or identity provider is only
instantiated when it is actually configured.

## What's a PSR service vs. what's package config

`McpServerFactory` and `McpAction` depend only on PSR interfaces
(`ContainerInterface`, `ResponseFactoryInterface`, `StreamFactoryInterface`,
`SessionStoreInterface` from `mcp/sdk`) — see
[Framework-agnostic usage](/framework-agnostic) for wiring the same classes
outside Yii3 entirely. `config/di.php` and `config/params.php` are the
`yiisoft/config-plugin` convenience layer specific to this package; they do
not add a Yii3 runtime dependency to the classes themselves.

Different entry points need different PSR services present in the container:

| Entry point / feature | Required services |
|---|---|
| `McpAction` | `ResponseFactoryInterface`, `StreamFactoryInterface` |
| `McpListCommand`, `McpTester` | `ServerRequestFactoryInterface`, `ResponseFactoryInterface`, `StreamFactoryInterface` |
| URL OpenAPI spec | PSR-18 `ClientInterface`, PSR-17 `RequestFactoryInterface` |
| OpenAPI operation execution | `ClientInterface`, `RequestFactoryInterface`, `StreamFactoryInterface` |
| URL spec cache | PSR-16 `CacheInterface`, when `openapi.cache_ttl > 0` |

`ServerRequestFactoryInterface` and `RequestFactoryInterface` are distinct
PSR-17 contracts — binding one does not satisfy the other. Keep these bound
in **every** config group that builds `Mcp\Server`, including the console
group used by `mcp:list`/`mcp:doctor`/`mcp:serve` — `mcp:doctor` reports a
missing service by its exact interface name.
