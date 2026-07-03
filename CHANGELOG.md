# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 — 2026-07-04

- Initial release: MCP (Model Context Protocol) server integration for Yii3
  over the official `mcp/sdk`.
- `McpServerFactory` — reads the SDK's `#[McpTool]`/`#[McpResource]` attributes
  off listed tool classes and registers `[class, method]` handlers resolved
  through the Yii3 DI container.
- `McpAction` (PSR-15) serves the Streamable HTTP transport with configurable
  allowed hosts (DNS-rebinding protection); `McpServeCommand` serves stdio.
- `SharedSecretMiddleware` guards the endpoint with clear 401/503 responses.
- `ConditionalToolInterface` for runtime tool gating and
  `ServerConfiguratorInterface` for custom server setup.
- OpenAPI bridge: `OpenApi\OpenApiServerConfigurator` + `OpenApi\SpecLoader`
  expose allow-listed REST operations as MCP tools, with a `safe_methods_only`
  read-only guard.
- `Prompts\MarkdownPromptsConfigurator` — a directory of `*.md` files served as
  MCP prompts.
- `Testing\McpTester` for exercising the server in tests.
- Exceptions in `Exception\`, `OpenApi\Exception\`, and `Prompts\Exception\`.
