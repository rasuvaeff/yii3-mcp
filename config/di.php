<?php

declare(strict_types=1);

use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3Mcp\Doctor\McpDoctor;
use Rasuvaeff\Yii3Mcp\Identity\StaticSecretResolver;
use Rasuvaeff\Yii3Mcp\Interceptor\CachingToolCallInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\PromptGetInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\ResourceReadInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\ResponseSizeLimitInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\SessionBudgetInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Visibility\DeclarativeToolVisibility;
use Rasuvaeff\Yii3Mcp\Visibility\PromptVisibilityInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ResourceVisibilityInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;
use Rasuvaeff\Yii3Mcp\McpAction;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\OpenApi\HttpOperationExecutor;
use Rasuvaeff\Yii3Mcp\OpenApi\DelegatedHeaderProviderInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentityProviderInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\OpenApiServerConfigurator;
use Rasuvaeff\Yii3Mcp\OpenApi\OperationModifierInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecLoader;
use Rasuvaeff\Yii3Mcp\Prompts\MarkdownPromptsConfigurator;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;

/** @var array $params */

// Session store default is FPM-safe (file-based): the MCP Streamable HTTP
// session spans several requests, so the SDK's in-memory default would lose
// it between FPM workers. Rebind to Psr16SessionStore for multi-host setups.

return [
    SessionStoreInterface::class => [
        'definition' => static function () use ($params): SessionStoreInterface {
            /** @var array{dir?: string, ttl?: int} $session */
            $session = $params['rasuvaeff/yii3-mcp']['session'] ?? [];
            $dir = $session['dir'] ?? '';

            return new FileSessionStore(
                directory: $dir === '' ? sys_get_temp_dir() . '/yii3-mcp-sessions' : $dir,
                ttl: $session['ttl'] ?? 3600,
            );
        },
    ],
    McpServerFactory::class => [
        '__construct()' => [
            'name' => $params['rasuvaeff/yii3-mcp']['server_name'],
            'version' => $params['rasuvaeff/yii3-mcp']['server_version'],
        ],
    ],
    Server::class => [
        'definition' => static function (McpServerFactory $factory, ContainerInterface $container) use ($params): Server {
            /** @var list<class-string> $tools */
            $tools = $params['rasuvaeff/yii3-mcp']['tools'];
            /** @var array{spec_path: string, base_url: string, operations: list<string>, headers: array<string, string>, cache_ttl?: int, identity_provider?: class-string<ExecutionIdentityProviderInterface>|'', delegated_header_provider?: class-string<DelegatedHeaderProviderInterface>|'', safe_methods_only?: bool, tool_names?: array<string, string>, operation_modifier?: class-string<OperationModifierInterface>|'', dry_run?: list<string>} $openapi */
            $openapi = $params['rasuvaeff/yii3-mcp']['openapi'];

            $configurators = [];

            $identityProviderClass = $openapi['identity_provider'] ?? '';
            /** @var ?ExecutionIdentityProviderInterface $identityProvider */
            $identityProvider = $identityProviderClass === '' ? null : $container->get($identityProviderClass);

            /** @var string $promptsPath */
            $promptsPath = $params['rasuvaeff/yii3-mcp']['prompts_path'] ?? '';

            if ($promptsPath !== '') {
                $configurators[] = new MarkdownPromptsConfigurator($promptsPath);
            }

            if ($openapi['spec_path'] !== '' && $openapi['operations'] !== []) {
                $cacheTtl = $openapi['cache_ttl'] ?? 0;

                $spec = str_starts_with($openapi['spec_path'], 'http://') || str_starts_with($openapi['spec_path'], 'https://')
                    ? (new SpecLoader(
                        httpClient: $container->get(ClientInterface::class),
                        requestFactory: $container->get(RequestFactoryInterface::class),
                        headers: $openapi['headers'],
                        cache: $cacheTtl > 0 ? $container->get(CacheInterface::class) : null,
                        cacheTtl: $cacheTtl,
                    ))->fromUrl($openapi['spec_path'])
                    : SpecIndex::fromFile($openapi['spec_path']);

                $delegatedHeaderProviderClass = $openapi['delegated_header_provider'] ?? '';

                /** @var ?DelegatedHeaderProviderInterface $delegatedHeaderProvider */
                $delegatedHeaderProvider = $delegatedHeaderProviderClass === '' ? null : $container->get($delegatedHeaderProviderClass);

                $operationModifierClass = $openapi['operation_modifier'] ?? '';
                /** @var ?OperationModifierInterface $operationModifier */
                $operationModifier = $operationModifierClass === '' ? null : $container->get($operationModifierClass);

                $configurators[] = new OpenApiServerConfigurator(
                    spec: $spec,
                    executor: new HttpOperationExecutor(
                        httpClient: $container->get(ClientInterface::class),
                        requestFactory: $container->get(RequestFactoryInterface::class),
                        streamFactory: $container->get(StreamFactoryInterface::class),
                        baseUrl: $openapi['base_url'],
                        defaultHeaders: $openapi['headers'],
                        identityProvider: $identityProvider,
                        delegatedHeaderProvider: $delegatedHeaderProvider,
                    ),
                    operations: $openapi['operations'],
                    safeMethodsOnly: $openapi['safe_methods_only'] ?? false,
                    toolNames: $openapi['tool_names'] ?? [],
                    modifier: $operationModifier,
                    dryRunOperations: $openapi['dry_run'] ?? [],
                );
            }

            /** @var list<class-string<ServerConfiguratorInterface>> $configuratorClasses */
            $configuratorClasses = $params['rasuvaeff/yii3-mcp']['configurators'] ?? [];

            foreach ($configuratorClasses as $configuratorClass) {
                $configurators[] = $container->get($configuratorClass);
            }

            $interceptors = [];

            /** @var array{budget?: int} $session */
            $session = $params['rasuvaeff/yii3-mcp']['session'] ?? [];
            $budget = $session['budget'] ?? 0;

            if ($budget > 0) {
                // budget goes first (outermost): the cheap guard runs before
                // any application interceptor does work
                $interceptors[] = new SessionBudgetInterceptor($budget);
            }

            /** @var list<class-string<ToolCallInterceptorInterface>> $interceptorClasses */
            $interceptorClasses = $params['rasuvaeff/yii3-mcp']['interceptors'] ?? [];

            foreach ($interceptorClasses as $interceptorClass) {
                $interceptors[] = $container->get($interceptorClass);
            }

            /** @var array<string, int> $cacheTools */
            $cacheTools = $params['rasuvaeff/yii3-mcp']['cache']['tools'] ?? [];

            if ($cacheTools !== []) {
                // wraps the size limit (added next, further in): user
                // interceptors (RBAC/audit) still run on EVERY call,
                // including a cache hit — no ACL bypass through the cache.
                // The size limit only runs on a cache miss; the value it
                // already limited is what gets cached, so a hit never
                // needs re-limiting. The identity provider (when delegated
                // auth is configured) partitions the key by ExecutionIdentity:
                // upstream responses fetched with one identity's credentials
                // must never be served to another, even under one client id
                $interceptors[] = new CachingToolCallInterceptor(
                    $container->get(CacheInterface::class),
                    $cacheTools,
                    identityProvider: $identityProvider,
                );
            }

            /** @var int $maxResultBytes */
            $maxResultBytes = $params['rasuvaeff/yii3-mcp']['limits']['tool_result_bytes'] ?? 0;

            if ($maxResultBytes > 0) {
                // innermost: closest to the actual tool call
                $interceptors[] = new ResponseSizeLimitInterceptor($maxResultBytes);
            }

            /** @var class-string<ToolVisibilityInterface>|'' $visibilityClass */
            $visibilityClass = $params['rasuvaeff/yii3-mcp']['tool_visibility'] ?? '';
            /** @var array{deny?: list<string>, allow?: list<string>} $declarative */
            $declarative = $params['rasuvaeff/yii3-mcp']['visibility'] ?? [];
            $deny = $declarative['deny'] ?? [];
            $allow = $declarative['allow'] ?? [];

            if ($visibilityClass !== '' && ($deny !== [] || $allow !== [])) {
                throw new LogicException('Configure either "tool_visibility" (a ToolVisibilityInterface class) or declarative "visibility" deny/allow lists, not both');
            }

            $visibility = null;

            if ($visibilityClass !== '') {
                /** @var ToolVisibilityInterface $visibility */
                $visibility = $container->get($visibilityClass);
            } elseif ($deny !== [] || $allow !== []) {
                $visibility = new DeclarativeToolVisibility(deny: $deny, allow: $allow);
            }

            $promptInterceptors = [];

            /** @var list<class-string<PromptGetInterceptorInterface>> $promptInterceptorClasses */
            $promptInterceptorClasses = $params['rasuvaeff/yii3-mcp']['prompt_interceptors'] ?? [];

            foreach ($promptInterceptorClasses as $promptInterceptorClass) {
                $promptInterceptors[] = $container->get($promptInterceptorClass);
            }

            $resourceInterceptors = [];

            /** @var list<class-string<ResourceReadInterceptorInterface>> $resourceInterceptorClasses */
            $resourceInterceptorClasses = $params['rasuvaeff/yii3-mcp']['resource_interceptors'] ?? [];

            foreach ($resourceInterceptorClasses as $resourceInterceptorClass) {
                $resourceInterceptors[] = $container->get($resourceInterceptorClass);
            }

            /** @var class-string<PromptVisibilityInterface>|'' $promptVisibilityClass */
            $promptVisibilityClass = $params['rasuvaeff/yii3-mcp']['prompt_visibility'] ?? '';
            /** @var PromptVisibilityInterface|null $promptVisibility */
            $promptVisibility = $promptVisibilityClass === '' ? null : $container->get($promptVisibilityClass);

            /** @var class-string<ResourceVisibilityInterface>|'' $resourceVisibilityClass */
            $resourceVisibilityClass = $params['rasuvaeff/yii3-mcp']['resource_visibility'] ?? '';
            /** @var ResourceVisibilityInterface|null $resourceVisibility */
            $resourceVisibility = $resourceVisibilityClass === '' ? null : $container->get($resourceVisibilityClass);

            return $factory->create(
                $tools,
                $configurators,
                $interceptors,
                $visibility,
                $promptInterceptors,
                $resourceInterceptors,
                $promptVisibility,
                $resourceVisibility,
            );
        },
    ],
    McpAction::class => [
        '__construct()' => [
            'allowedHosts' => $params['rasuvaeff/yii3-mcp']['allowed_hosts'],
        ],
    ],
    SharedSecretMiddleware::class => [
        'definition' => static function (ResponseFactoryInterface $responseFactory) use ($params): SharedSecretMiddleware {
            /** @var array<string, string|list<string>> $clientSecrets */
            $clientSecrets = $params['rasuvaeff/yii3-mcp']['client_secrets'] ?? [];

            return new SharedSecretMiddleware(
                secret: $params['rasuvaeff/yii3-mcp']['endpoint_secret'],
                responseFactory: $responseFactory,
                headerName: $params['rasuvaeff/yii3-mcp']['secret_header'],
                resolver: $clientSecrets === [] ? null : new StaticSecretResolver($clientSecrets),
            );
        },
    ],
    McpDoctor::class => [
        'definition' => static function (ContainerInterface $container) use ($params): McpDoctor {
            /** @var array{dir?: string} $session */
            $session = $params['rasuvaeff/yii3-mcp']['session'] ?? [];
            $dir = $session['dir'] ?? '';
            /** @var array{spec_path: string, operations: list<string>, headers: array<string, string>, cache_ttl?: int} $openapi */
            $openapi = $params['rasuvaeff/yii3-mcp']['openapi'];

            /** @var array<string, string|list<string>> $clientSecrets */
            $clientSecrets = $params['rasuvaeff/yii3-mcp']['client_secrets'] ?? [];

            return new McpDoctor(
                container: $container,
                sessionStore: $container->get(SessionStoreInterface::class),
                endpointSecret: $params['rasuvaeff/yii3-mcp']['endpoint_secret'],
                sessionDirectory: $dir === '' ? sys_get_temp_dir() . '/yii3-mcp-sessions' : $dir,
                openApiSpecPath: $openapi['spec_path'],
                openApiHeaders: $openapi['headers'],
                clientSecretIds: array_map(strval(...), array_keys($clientSecrets)),
                openApiOperationsEnabled: $openapi['operations'] !== [],
                openApiCacheTtl: $openapi['cache_ttl'] ?? 0,
                expectedHttpHost: $params['rasuvaeff/yii3-mcp']['expected_http_host'] ?? '',
                allowedHosts: $params['rasuvaeff/yii3-mcp']['allowed_hosts'],
                toolResultCacheEnabled: ($params['rasuvaeff/yii3-mcp']['cache']['tools'] ?? []) !== [],
            );
        },
    ],
];
