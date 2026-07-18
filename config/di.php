<?php

declare(strict_types=1);

use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Mcp\Doctor\McpDoctor;
use Rasuvaeff\Yii3Mcp\Interceptor\SessionBudgetInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Visibility\DeclarativeToolVisibility;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;
use Rasuvaeff\Yii3Mcp\McpAction;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\OpenApi\HttpOperationExecutor;
use Rasuvaeff\Yii3Mcp\OpenApi\OpenApiServerConfigurator;
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
            /** @var array{spec_path: string, base_url: string, operations: list<string>, headers: array<string, string>, safe_methods_only?: bool} $openapi */
            $openapi = $params['rasuvaeff/yii3-mcp']['openapi'];

            $configurators = [];

            /** @var string $promptsPath */
            $promptsPath = $params['rasuvaeff/yii3-mcp']['prompts_path'] ?? '';

            if ($promptsPath !== '') {
                $configurators[] = new MarkdownPromptsConfigurator($promptsPath);
            }

            if ($openapi['spec_path'] !== '' && $openapi['operations'] !== []) {
                // http(s) source: fetched with the same headers as the calls,
                // so a spec endpoint behind auth works and is always current
                $spec = str_starts_with($openapi['spec_path'], 'http://') || str_starts_with($openapi['spec_path'], 'https://')
                    ? (new SpecLoader(
                        httpClient: $container->get(ClientInterface::class),
                        requestFactory: $container->get(RequestFactoryInterface::class),
                        headers: $openapi['headers'],
                    ))->fromUrl($openapi['spec_path'])
                    : SpecIndex::fromFile($openapi['spec_path']);

                $configurators[] = new OpenApiServerConfigurator(
                    spec: $spec,
                    executor: new HttpOperationExecutor(
                        httpClient: $container->get(ClientInterface::class),
                        requestFactory: $container->get(RequestFactoryInterface::class),
                        streamFactory: $container->get(StreamFactoryInterface::class),
                        baseUrl: $openapi['base_url'],
                        defaultHeaders: $openapi['headers'],
                    ),
                    operations: $openapi['operations'],
                    safeMethodsOnly: $openapi['safe_methods_only'] ?? false,
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

            return $factory->create(
                $tools,
                $configurators,
                $interceptors,
                $visibility,
            );
        },
    ],
    McpAction::class => [
        '__construct()' => [
            'allowedHosts' => $params['rasuvaeff/yii3-mcp']['allowed_hosts'],
        ],
    ],
    SharedSecretMiddleware::class => [
        '__construct()' => [
            'secret' => $params['rasuvaeff/yii3-mcp']['endpoint_secret'],
            'headerName' => $params['rasuvaeff/yii3-mcp']['secret_header'],
        ],
    ],
    McpDoctor::class => [
        'definition' => static function (ContainerInterface $container) use ($params): McpDoctor {
            /** @var array{dir?: string} $session */
            $session = $params['rasuvaeff/yii3-mcp']['session'] ?? [];
            $dir = $session['dir'] ?? '';
            /** @var array{spec_path: string, headers: array<string, string>} $openapi */
            $openapi = $params['rasuvaeff/yii3-mcp']['openapi'];

            return new McpDoctor(
                container: $container,
                sessionStore: $container->get(SessionStoreInterface::class),
                endpointSecret: $params['rasuvaeff/yii3-mcp']['endpoint_secret'],
                sessionDirectory: $dir === '' ? sys_get_temp_dir() . '/yii3-mcp-sessions' : $dir,
                openApiSpecPath: $openapi['spec_path'],
                openApiHeaders: $openapi['headers'],
            );
        },
    ],
];
