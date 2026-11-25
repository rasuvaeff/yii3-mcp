<?php

declare(strict_types=1);

use Mcp\Server\Server;
use Mcp\Server\Resource\SessionSubscriptionManager;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Subscription\NotificationBusInterface;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadataHandler;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Mcp\Doctor\McpDoctor;
use Rasuvaeff\Mcp\Configuration\ServerOptions;
use Rasuvaeff\Mcp\McpAction;
use Rasuvaeff\Mcp\McpServerAssembler;
use Rasuvaeff\Yii3Mcp\McpServerComponentResolver;
use Rasuvaeff\Mcp\McpServerFactory;
use Rasuvaeff\Mcp\OAuth\BearerTokenMiddleware;
use Rasuvaeff\Mcp\OAuth\OAuthComponentFactory;
use Rasuvaeff\Mcp\Session\SessionDirectory;
use Rasuvaeff\Mcp\SharedSecretMiddleware;

/** @var array $params */

// Parsed at config load, not at the first request: an unsupported value here
// would otherwise surface as an opaque error deep in the server build.
$options = ServerOptions::fromParams($params['rasuvaeff/yii3-mcp']);

// Bound only when configured: McpServerFactory and ResourceUpdateNotifier take
// it as an optional dependency, and must share ONE instance — a memory bus the
// notifier publishes to must be the one listen streams read.
$notificationBusDefinition = match ($options->notificationBus) {
    'memory' => [NotificationBusInterface::class => static fn(): NotificationBusInterface => new InMemoryNotificationBus()],
    'psr16' => [NotificationBusInterface::class => static fn(CacheInterface $cache): NotificationBusInterface => $options->psr16NotificationBus($cache)],
    'psr6' => [NotificationBusInterface::class => static fn(CacheItemPoolInterface $cache): NotificationBusInterface => $options->psr6NotificationBus($cache)],
    default => [],
};

// Session store default is FPM-safe (file-based): the MCP Streamable HTTP
// session spans several requests, so the SDK's in-memory default would lose
// it between FPM workers. Rebind to Psr16SessionStore for multi-host setups.

return [
    SessionStoreInterface::class => [
        'definition' => static fn(): SessionStoreInterface => $options->sessionStore(),
    ],
    // backs resources/subscribe AND is what Resource\ResourceUpdateNotifier
    // reads, so both sides of a subscription agree by construction
    SubscriptionManagerInterface::class => SessionSubscriptionManager::class,
    ...$notificationBusDefinition,
    McpServerFactory::class => [
        '__construct()' => $options->factoryArguments(),
    ],
    Server::class => [
        'definition' => static fn (
            McpServerFactory $factory,
            ContainerInterface $container,
        ): Server => (new McpServerAssembler(
            factory: $factory,
            components: (new McpServerComponentResolver(
                container: $container,
                params: $params['rasuvaeff/yii3-mcp'],
            ))->resolve(),
        ))->create(),
    ],
    McpAction::class => [
        'definition' => static function (
            Server $server,
            ResponseFactoryInterface $responseFactory,
            StreamFactoryInterface $streamFactory,
            SessionStoreInterface $sessionStore,
        ) use ($options): McpAction {
            // the session store is passed so sessions are BOUND to the client
            // that created them (owner stamped at initialize, verified on
            // every POST/DELETE) — without it any authenticated client could
            // replay another client's Mcp-Session-Id
            return new McpAction(
                server: $server,
                responseFactory: $responseFactory,
                streamFactory: $streamFactory,
                allowedHosts: $options->allowedHosts(),
                sessionStore: $sessionStore,
            );
        },
    ],
    SharedSecretMiddleware::class => [
        'definition' => static fn(ResponseFactoryInterface $responseFactory): SharedSecretMiddleware => $options->sharedSecretMiddleware($responseFactory),
    ],
    // resolved only when routed: an application without OAuth never builds them
    BearerTokenMiddleware::class => [
        'definition' => static fn (ContainerInterface $container): BearerTokenMiddleware => (new OAuthComponentFactory(
            params: $params['rasuvaeff/yii3-mcp']['oauth'] ?? [],
            container: $container,
        ))->bearerTokenMiddleware(),
    ],
    ProtectedResourceMetadataHandler::class => [
        'definition' => static fn (ContainerInterface $container): ProtectedResourceMetadataHandler => (new OAuthComponentFactory(
            params: $params['rasuvaeff/yii3-mcp']['oauth'] ?? [],
            container: $container,
        ))->metadataHandler(),
    ],
    McpDoctor::class => [
        'definition' => static function (ContainerInterface $container) use ($params, $options): McpDoctor {
            /** @var array{dir?: string} $session */
            $session = $params['rasuvaeff/yii3-mcp']['session'] ?? [];
            /** @var string $serverName */
            $serverName = $params['rasuvaeff/yii3-mcp']['server_name'];
            /** @var array{spec_path: string, operations: list<string>, spec_headers?: array<string, string>, cache_ttl?: int, executor?: string, handler?: string} $openapi */
            $openapi = $params['rasuvaeff/yii3-mcp']['openapi'];

            /** @var array<string, string|list<string>> $clientSecrets */
            $clientSecrets = $params['rasuvaeff/yii3-mcp']['client_secrets'] ?? [];

            /** @var array{enable?: bool, definitions?: list<array<string, mixed>>} $apps */
            $apps = $params['rasuvaeff/yii3-mcp']['apps'] ?? [];

            return new McpDoctor(
                container: $container,
                sessionStore: $container->get(SessionStoreInterface::class),
                endpointSecret: $params['rasuvaeff/yii3-mcp']['endpoint_secret'],
                sessionDirectory: SessionDirectory::resolve($session['dir'] ?? '', $serverName),
                openApiSpecPath: $openapi['spec_path'],
                openApiHeaders: $openapi['spec_headers'] ?? [],
                clientSecretIds: array_map(strval(...), array_keys($clientSecrets)),
                openApiOperationsEnabled: $openapi['operations'] !== [],
                openApiCacheTtl: $openapi['cache_ttl'] ?? 0,
                expectedHttpHost: $params['rasuvaeff/yii3-mcp']['expected_http_host'] ?? '',
                allowedHosts: $params['rasuvaeff/yii3-mcp']['allowed_hosts'],
                toolResultCacheEnabled: ($params['rasuvaeff/yii3-mcp']['cache']['tools'] ?? []) !== [],
                appsEnabled: $apps['enable'] ?? false,
                appDefinitions: $apps['definitions'] ?? [],
                openApiInProcess: ($openapi['executor'] ?? 'http') === 'psr15',
                openApiInProcessHandler: $openapi['handler'] ?? '',
                modernEra: (bool) ($params['rasuvaeff/yii3-mcp']['modern_era'] ?? true),
                toolCallBudgetEnabled: (int) ($params['rasuvaeff/yii3-mcp']['tool_call_budget']['calls'] ?? 0) > 0,
                notificationBus: $options->notificationBus,
                requestStateKeyBytes: strlen((string) ($params['rasuvaeff/yii3-mcp']['request_state']['key'] ?? '')),
                oauthConfigured: ($params['rasuvaeff/yii3-mcp']['oauth']['resource'] ?? '') !== ''
                    && ($params['rasuvaeff/yii3-mcp']['oauth']['authorization_servers'] ?? []) !== [],
                externalAuth: (bool) ($params['rasuvaeff/yii3-mcp']['external_auth'] ?? false),
            );
        },
    ],
];
