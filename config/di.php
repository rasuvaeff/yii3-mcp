<?php

declare(strict_types=1);

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server;
use Mcp\Server\Resource\SessionSubscriptionManager;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Subscription\NotificationBusInterface;
use Mcp\Server\Subscription\Psr16NotificationBus;
use Psr\SimpleCache\CacheInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Mcp\Doctor\McpDoctor;
use Rasuvaeff\Yii3Mcp\Identity\StaticSecretResolver;
use Rasuvaeff\Yii3Mcp\McpAction;
use Rasuvaeff\Yii3Mcp\McpServerAssembler;
use Rasuvaeff\Yii3Mcp\McpServerComponentResolver;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Session\PrivateFileSessionStore;
use Rasuvaeff\Yii3Mcp\Session\SessionDirectory;
use Rasuvaeff\Yii3Mcp\SharedSecretMiddleware;

/** @var array $params */

// Fail at config load, not at the first request: an unsupported revision here
// would otherwise surface as an opaque SDK error deep in the server build.
$protocolVersionParam = (string) ($params['rasuvaeff/yii3-mcp']['protocol_version'] ?? '');
$handshakeVersion = ProtocolVersion::tryFrom($protocolVersionParam);
$protocolVersion = $protocolVersionParam === ''
    ? null
    : ($handshakeVersion instanceof ProtocolVersion && !$handshakeVersion->isModern()
        ? $handshakeVersion
        : throw new InvalidArgumentException(sprintf(
            'Unsupported MCP handshake protocol version "%s"; supported: %s (the stateless era is "modern_era")',
            $protocolVersionParam,
            implode(', ', array_map(static fn(ProtocolVersion $version): string => $version->value, ProtocolVersion::handshakeVersions())),
        )));

// Same fail-early contract for the result-JSON knob: a typo like "compct"
// would otherwise silently serve pretty-printed results forever.
$resultJson = (string) ($params['rasuvaeff/yii3-mcp']['result_json'] ?? McpServerFactory::RESULT_JSON_PRETTY);
$compactToolResults = in_array($resultJson, [McpServerFactory::RESULT_JSON_PRETTY, McpServerFactory::RESULT_JSON_COMPACT], true)
    ? $resultJson === McpServerFactory::RESULT_JSON_COMPACT
    : throw new InvalidArgumentException(sprintf(
        'Unsupported result_json "%s"; supported: %s, %s',
        $resultJson,
        McpServerFactory::RESULT_JSON_PRETTY,
        McpServerFactory::RESULT_JSON_COMPACT,
    ));

// Same for the notification bus: an unknown kind would otherwise leave
// stateless listen streams silently empty.
$notificationBus = (string) ($params['rasuvaeff/yii3-mcp']['notifications']['bus'] ?? '');

if (!in_array($notificationBus, ['', 'psr16', 'memory'], true)) {
    throw new InvalidArgumentException(sprintf('Unsupported notifications.bus "%s"; supported: "" (none), psr16, memory', $notificationBus));
}

// Bound only when configured: McpServerFactory and ResourceUpdateNotifier take
// it as an optional dependency, and must share ONE instance — a memory bus the
// notifier publishes to must be the one listen streams read.
$notificationBusDefinition = match ($notificationBus) {
    '' => [],
    'memory' => [NotificationBusInterface::class => static fn(): NotificationBusInterface => new InMemoryNotificationBus()],
    'psr16' => [
        NotificationBusInterface::class => static fn(CacheInterface $cache): NotificationBusInterface => new Psr16NotificationBus(
            cache: $cache,
            // per application, short: PSR-16 guarantees 64-character keys
            prefix: 'mcp.n.' . substr(hash('sha256', (string) $params['rasuvaeff/yii3-mcp']['server_name']), 0, 12) . '.',
        ),
    ],
};

// Session store default is FPM-safe (file-based): the MCP Streamable HTTP
// session spans several requests, so the SDK's in-memory default would lose
// it between FPM workers. Rebind to Psr16SessionStore for multi-host setups.

return [
    SessionStoreInterface::class => [
        'definition' => static function () use ($params): SessionStoreInterface {
            /** @var array{dir?: string, ttl?: int} $session */
            $session = $params['rasuvaeff/yii3-mcp']['session'] ?? [];
            /** @var string $serverName */
            $serverName = $params['rasuvaeff/yii3-mcp']['server_name'];

            // owner-only (0700 dir, 0600 files) and application-specific by
            // default: session JSON carries client metadata and everything
            // needed to replay a session id — it must not be readable by
            // other OS users or shared between applications on one host
            return new PrivateFileSessionStore(
                directory: SessionDirectory::resolve($session['dir'] ?? '', $serverName),
                ttl: $session['ttl'] ?? 3600,
            );
        },
    ],
    // backs resources/subscribe AND is what Resource\ResourceUpdateNotifier
    // reads, so both sides of a subscription agree by construction
    SubscriptionManagerInterface::class => SessionSubscriptionManager::class,
    ...$notificationBusDefinition,
    McpServerFactory::class => [
        '__construct()' => [
            'name' => $params['rasuvaeff/yii3-mcp']['server_name'],
            'version' => $params['rasuvaeff/yii3-mcp']['server_version'],
            'instructions' => $params['rasuvaeff/yii3-mcp']['instructions'] ?? '',
            'paginationLimit' => $params['rasuvaeff/yii3-mcp']['pagination_limit'] ?? McpServerFactory::DEFAULT_PAGINATION_LIMIT,
            'protocolVersion' => $protocolVersion,
            'compactToolResults' => $compactToolResults,
            'modernEra' => (bool) ($params['rasuvaeff/yii3-mcp']['modern_era'] ?? true),
            'headerValidation' => (bool) ($params['rasuvaeff/yii3-mcp']['header_validation'] ?? true),
            'subscriptionLifetime' => (float) ($params['rasuvaeff/yii3-mcp']['notifications']['subscription_lifetime'] ?? McpServerFactory::DEFAULT_SUBSCRIPTION_LIFETIME),
        ],
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
        ) use ($params): McpAction {
            /** @var list<string> $allowedHosts */
            $allowedHosts = $params['rasuvaeff/yii3-mcp']['allowed_hosts'];

            // the session store is passed so sessions are BOUND to the client
            // that created them (owner stamped at initialize, verified on
            // every POST/DELETE) — without it any authenticated client could
            // replay another client's Mcp-Session-Id
            return new McpAction(
                server: $server,
                responseFactory: $responseFactory,
                streamFactory: $streamFactory,
                allowedHosts: $allowedHosts,
                sessionStore: $sessionStore,
            );
        },
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
            );
        },
    ],
];
