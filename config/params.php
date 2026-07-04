<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-mcp' => [
        'server_name' => 'yii3-mcp',
        'server_version' => 'dev',
        // list of application tool classes (public methods annotated with the
        // SDK #[McpTool] / #[McpResource] attributes); the core registers none
        'tools' => [],
        // fail-closed: SharedSecretMiddleware refuses to instantiate while
        // this is empty — set a secret or protect the endpoint with a network ACL
        'endpoint_secret' => '',
        'secret_header' => 'X-Mcp-Secret',
        // extra hosts for the transport's DNS-rebinding protection
        // (localhost is always allowed); required when the endpoint is
        // served from a real domain, e.g. ['api.example.com']
        'allowed_hosts' => [],
        'session' => [
            // empty => sys_get_temp_dir() . '/yii3-mcp-sessions'
            'dir' => '',
            'ttl' => 3600,
            // max tools/call per session (0 = unlimited); anti-loop guard,
            // NOT a client quota — a new session starts a fresh counter
            'budget' => 0,
        ],
        // tool-call interceptor FQCNs (resolved through the container,
        // applied in order, first = outermost); each implements
        // Interceptor\ToolCallInterceptorInterface
        'interceptors' => [],
        // Markdown prompts directory: every *.md file becomes an MCP prompt
        // (YAML frontmatter: name/title/description/arguments; body with
        // {{argument}} placeholders). Format is vjik/my-prompts-mcp compatible.
        // Empty = disabled.
        'prompts_path' => '',
        // OpenAPI bridge: expose allow-listed REST operations as MCP tools.
        // Disabled while spec_path is empty; an empty operations list exposes nothing.
        // spec_path accepts a file path OR an http(s) URL (e.g. the app's own
        // spec endpoint — always current; fetched with the same `headers`).
        'openapi' => [
            'spec_path' => '',
            'base_url' => '',
            'operations' => [],
            'headers' => [],
            // read-only bridge: reject non-GET operations at build time
            'safe_methods_only' => false,
        ],
    ],
];
