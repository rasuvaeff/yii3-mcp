<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-mcp' => [
        'server_name' => 'yii3-mcp',
        'server_version' => 'dev',
        // list of application tool classes (public methods annotated with the
        // SDK #[McpTool] / #[McpResource] attributes); the core registers none
        'tools' => [],
        // fail-closed: SharedSecretMiddleware answers 503 while neither this
        // nor 'client_secrets' is set — set a secret or protect the endpoint
        // with a network ACL. Mutually exclusive with 'client_secrets'.
        'endpoint_secret' => '',
        // several clients / secret rotation: client id => secret or list of
        // ACTIVE secrets (two secrets under one id = rotation window; a
        // removed secret is revoked immediately). The resolved client id is
        // exposed to interceptors (ToolCallContext::$clientId) and mirrored
        // into the session; the raw secret never travels further than the
        // middleware. Mutually exclusive with 'endpoint_secret'.
        'client_secrets' => [],
        'secret_header' => 'X-Mcp-Secret',
        // extra hosts for the transport's DNS-rebinding protection
        // (localhost is always allowed); required when the endpoint is
        // served from a real domain, e.g. ['api.example.com']
        'allowed_hosts' => [],
        // Optional production hostname expected by mcp:doctor. Empty means no
        // host-policy check (localhost and stdio remain valid deployments).
        'expected_http_host' => '',
        'session' => [
            // empty => sys_get_temp_dir() . '/yii3-mcp-sessions'
            'dir' => '',
            'ttl' => 3600,
            // max tools/call per session (0 = unlimited); anti-loop guard,
            // NOT a client quota — a new session starts a fresh counter
            'budget' => 0,
        ],
        // guard against a tool result burning an agent's context window
        // (0 = unlimited). A string result over the limit is truncated with
        // a marker; any other result (array/object) is rejected outright —
        // truncated JSON is invalid JSON, not a smaller valid one.
        'limits' => [
            'tool_result_bytes' => 0,
            // cap on a substituted Markdown prompt's text: placeholder
            // substitution multiplies a caller-supplied argument by its
            // occurrence count, so the output is bounded BEFORE it is built
            // (0 = unlimited)
            'prompt_result_bytes' => 1_048_576,
        ],
        // PSR-16 cache for successful tool results: tool name => TTL in
        // seconds. Empty (default) = no caching. The cache key always
        // includes the resolved client id — never share cached results
        // between clients — and, when openapi.identity_provider is set, the
        // resolved ExecutionIdentity (delegated credentials can make results
        // identity-specific below the client-id level). Opt in only tools
        // that are safe to cache (idempotent reads); the interceptor has no
        // notion of which are. "Idempotent" is not enough on its own: a tool
        // whose result depends on the application user behind the MCP client
        // is only safe to cache when openapi.identity_provider resolves that
        // user, since the key otherwise identifies the client alone.
        'cache' => [
            'tools' => [],
            // stable application/server identity isolating this server's
            // entries on a cache backend shared between applications (two
            // apps on one Redis with same-named tools must never read each
            // other's results). Empty = server_name.
            'namespace' => '',
        ],
        // tool-call interceptor FQCNs (resolved through the container,
        // applied in order, first = outermost); each implements
        // Interceptor\ToolCallInterceptorInterface
        'interceptors' => [],
        // prompts/get interceptor FQCNs (resolved through the container,
        // applied in order, first = outermost); each implements
        // Interceptor\PromptGetInterceptorInterface
        'prompt_interceptors' => [],
        // resources/read interceptor FQCNs (static resources and templates
        // alike; resolved through the container, applied in order, first =
        // outermost); each implements Interceptor\ResourceReadInterceptorInterface
        'resource_interceptors' => [],
        // server configurator FQCNs (resolved through the container, applied
        // in order after the core's own prompts/openapi configurators); each
        // implements ServerConfiguratorInterface. Extension point for
        // companion packages and app-specific server setup.
        'configurators' => [],
        // per-session tool visibility: FQCN of a Visibility\ToolVisibilityInterface
        // implementation (resolved through the container). Filters tools/list AND
        // fail-closed rejects tools/call of invisible tools. Empty = all visible.
        // Mutually exclusive with the declarative 'visibility' lists below.
        'tool_visibility' => '',
        // per-session prompt visibility: FQCN of a Visibility\PromptVisibilityInterface
        // implementation. Filters prompts/list AND fail-closed hides prompts/get
        // (a hidden prompt is reported as not found). Empty = all visible.
        'prompt_visibility' => '',
        // per-session resource visibility: FQCN of a Visibility\ResourceVisibilityInterface
        // implementation. Filters resources/list + resources/templates/list AND
        // fail-closed hides resources/read (a hidden resource is reported as
        // not found). Empty = all visible.
        'resource_visibility' => '',
        // declarative visibility: tool-name patterns with '*' wildcards
        // ('admin.*', '*.delete'). deny hides matches; a non-empty allow hides
        // everything it does not match; deny wins over allow. Both empty =
        // all visible. For per-session logic use 'tool_visibility' instead.
        'visibility' => [
            'deny' => [],
            'allow' => [],
        ],
        // Markdown prompts directory: every *.md file becomes an MCP prompt
        // (YAML frontmatter: name/title/description/arguments; body with
        // {{argument}} placeholders). Format is vjik/my-prompts-mcp compatible.
        // Empty = disabled.
        'prompts_path' => '',
        // OpenAPI bridge: expose allow-listed REST operations as MCP tools.
        // Disabled while spec_path is empty; an empty operations list exposes nothing.
        // spec_path accepts a file path OR an http(s) URL (e.g. the app's own
        // spec endpoint — always current; fetched with `spec_headers`, NOT
        // with the operation `headers`).
        'openapi' => [
            'spec_path' => '',
            'base_url' => '',
            'operations' => [],
            // rename operationId => tool name (e.g. ugly generated
            // operationIds into LLM-friendly names). Allow-list, handler
            // execution and delegated headers stay keyed by operationId;
            // interceptors/visibility rules must reference the RENAMED name.
            'tool_names' => [],
            // FQCN of an OpenApi\OperationModifierInterface, resolved through
            // the container; called once per bridged operation after the
            // tool_names rename to customize description/annotations/name
            // further. Empty = disabled.
            'operation_modifier' => '',
            // operation-call headers, sent to base_url only (e.g. the API's
            // service token). Deliberately NOT sent with the spec fetch:
            // when spec_path lives on a different origin, a shared header
            // set would hand the API token to the spec host.
            'headers' => [],
            // spec-fetch headers, sent to spec_path only. Empty by default —
            // set explicitly when the spec endpoint itself needs auth.
            'spec_headers' => [],
            // PSR-16 TTL for URL specs. 0 preserves fetch-on-every-build.
            'cache_ttl' => 0,
            // upper bound on an upstream response body the executor will
            // buffer; the read stops (and the call fails) before a byte over
            // the cap is materialized
            'max_response_bytes' => 4_194_304,
            // suppress the upstream error-body excerpt in bridged failures —
            // for service-token deployments where the upstream's error
            // details are not the MCP caller's to see
            'opaque_errors' => false,
            // Optional delegated mode. Configure both application services;
            // they are resolved on every operation call. Static headers stay
            // the backward-compatible service-token mode.
            'identity_provider' => '',
            'delegated_header_provider' => '',
            // read-only bridge: reject non-GET operations at build time
            'safe_methods_only' => false,
            // operationIds that get an extra `dryRun` boolean argument; a call
            // with `dryRun: true` returns the planned request (method, url,
            // body) instead of executing it. Orthogonal to safe_methods_only —
            // does not expose an operation the safety gate would otherwise
            // reject.
            'dry_run' => [],
        ],
    ],
];
