<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OAuth;

use Composer\InstalledVersions;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadataHandler;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the SDK's OAuth resource-server pieces from the package's `oauth`
 * params. Every misconfiguration fails here, when the guard is first
 * resolved, with a message naming the param — never as a request that is
 * silently let through.
 *
 * @internal
 */
final readonly class OAuthComponentFactory
{
    /** Package the SDK's JwtTokenValidator needs at runtime, only suggested by this one */
    private const string JWT_PACKAGE = 'firebase/php-jwt';

    /**
     * @param array<string, mixed> $params the `rasuvaeff/yii3-mcp` `oauth` block
     */
    public function __construct(
        private array $params,
        private ContainerInterface $container,
    ) {}

    public function bearerTokenMiddleware(): BearerTokenMiddleware
    {
        return new BearerTokenMiddleware(
            authorization: $this->authorizationMiddleware(),
            responseFactory: $this->service(ResponseFactoryInterface::class),
            identity: $this->string('identity', BearerTokenMiddleware::IDENTITY_PRINCIPAL),
        );
    }

    public function metadataHandler(): ProtectedResourceMetadataHandler
    {
        return new ProtectedResourceMetadataHandler(
            $this->metadata(),
            $this->service(ResponseFactoryInterface::class),
            $this->service(StreamFactoryInterface::class),
        );
    }

    public function authorizationMiddleware(): AuthorizationMiddleware
    {
        // the cheap params first: a typo is reported before the validator
        // reaches for the cache or the issuer
        $metadata = $this->metadata();
        $scopePolicy = $this->scopePolicy();

        return new AuthorizationMiddleware(
            validator: $this->validator(),
            resourceMetadata: $metadata,
            scopePolicy: $scopePolicy,
            responseFactory: $this->service(ResponseFactoryInterface::class),
            streamFactory: $this->service(StreamFactoryInterface::class),
            logger: $this->container->has(LoggerInterface::class) ? $this->service(LoggerInterface::class) : new NullLogger(),
        );
    }

    public function metadata(): ProtectedResourceMetadata
    {
        $resource = $this->string('resource', '');
        if ($resource === '') {
            throw new \InvalidArgumentException('OAuth is not configured: set rasuvaeff/yii3-mcp oauth.resource to the canonical URI of the MCP endpoint (e.g. https://mcp.example.com/mcp)');
        }

        $scopesSupported = $this->list('scopes_supported');
        $name = $this->string('resource_name', '');
        $documentation = $this->string('resource_documentation', '');

        return new ProtectedResourceMetadata(
            $resource,
            $this->authorizationServers(),
            $scopesSupported === [] ? null : $scopesSupported,
            $name === '' ? null : $name,
            $documentation === '' ? null : $documentation,
        );
    }

    /**
     * Null when no scope is required anywhere: the policy would only cost a
     * body read.
     */
    public function scopePolicy(): ?ScopePolicy
    {
        /** @var mixed $scopes */
        $scopes = $this->params['scopes'] ?? [];
        if (!is_array($scopes)) {
            throw new \InvalidArgumentException('rasuvaeff/yii3-mcp oauth.scopes must be an array with default, methods, tools and implies');
        }

        $unknown = array_diff(array_keys($scopes), ['default', 'methods', 'tools', 'implies']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown rasuvaeff/yii3-mcp oauth.scopes key(s): %s; supported: default, methods, tools, implies', implode(', ', $unknown)));
        }

        $default = $this->stringList($scopes['default'] ?? [], 'oauth.scopes.default');
        $methods = $this->scopeMap($scopes['methods'] ?? [], 'oauth.scopes.methods');
        $tools = $this->scopeMap($scopes['tools'] ?? [], 'oauth.scopes.tools');
        $implies = $this->scopeMap($scopes['implies'] ?? [], 'oauth.scopes.implies');

        if ($default === [] && $methods === [] && $tools === []) {
            return null;
        }

        return new ScopePolicy($default, $methods, $tools, $implies);
    }

    public function validator(): AuthorizationTokenValidatorInterface
    {
        $custom = $this->string('validator', '');
        if ($custom !== '') {
            /** @var mixed $validator */
            $validator = $this->container->get($custom);
            if (!$validator instanceof AuthorizationTokenValidatorInterface) {
                throw new \InvalidArgumentException(sprintf('rasuvaeff/yii3-mcp oauth.validator "%s" must resolve to %s', $custom, AuthorizationTokenValidatorInterface::class));
            }

            return $validator;
        }

        if (!InstalledVersions::isInstalled(self::JWT_PACKAGE)) {
            throw new \LogicException('OAuth JWT validation needs firebase/php-jwt: composer require firebase/php-jwt (or set rasuvaeff/yii3-mcp oauth.validator to your own token validator)');
        }

        $issuer = $this->string('issuer', '');
        $audience = $this->list('audience');
        $tokenType = $this->string('token_type', '');
        $algorithms = $this->list('algorithms');
        $scopeClaim = $this->string('scope_claim', 'scope');
        $leeway = $this->int('leeway');

        return JwtTokenValidator::fromIssuer(
            issuer: $issuer !== '' ? $issuer : $this->authorizationServers()[0],
            audience: $audience !== [] ? $audience : $this->metadata()->getResource(),
            cache: $this->cache(),
            httpClient: $this->container->has(ClientInterface::class) ? $this->service(ClientInterface::class) : null,
            requestFactory: $this->container->has(RequestFactoryInterface::class) ? $this->service(RequestFactoryInterface::class) : null,
            algorithms: $algorithms !== [] ? $algorithms : ['RS256'],
            scopeClaim: $scopeClaim,
            tokenType: $tokenType === '' ? null : $tokenType,
            leeway: $leeway,
        );
    }

    /**
     * @return non-empty-list<string>
     */
    private function authorizationServers(): array
    {
        $servers = $this->list('authorization_servers');
        if ($servers === []) {
            throw new \InvalidArgumentException('rasuvaeff/yii3-mcp oauth.authorization_servers must list at least one issuer that issues tokens for this server');
        }

        return $servers;
    }

    private function cache(): CacheItemPoolInterface
    {
        $id = $this->string('cache', '');
        /** @var mixed $cache */
        $cache = $this->container->get($id !== '' ? $id : CacheItemPoolInterface::class);
        if (!$cache instanceof CacheItemPoolInterface) {
            throw new \InvalidArgumentException(sprintf('rasuvaeff/yii3-mcp oauth.cache must resolve to %s (it caches the issuer metadata and the signing keys)', CacheItemPoolInterface::class));
        }

        return $cache;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        /** @var mixed $service */
        $service = $this->container->get($id);
        if (!$service instanceof $id) {
            throw new \InvalidArgumentException(sprintf('The container entry %s is not an instance of it', $id));
        }

        return $service;
    }

    private function string(string $key, string $default): string
    {
        /** @var mixed $value */
        $value = $this->params[$key] ?? $default;
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('rasuvaeff/yii3-mcp oauth.%s must be a string', $key));
        }

        return $value;
    }

    private function int(string $key): int
    {
        /** @var mixed $value */
        $value = $this->params[$key] ?? 0;
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException(sprintf('rasuvaeff/yii3-mcp oauth.%s must be a non-negative integer', $key));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function list(string $key): array
    {
        return $this->stringList($this->params[$key] ?? [], 'oauth.' . $key);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $name): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException(sprintf('rasuvaeff/yii3-mcp %s must be a list of strings', $name));
        }

        $strings = [];
        /** @var mixed $item */
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new \InvalidArgumentException(sprintf('rasuvaeff/yii3-mcp %s must be a list of non-empty strings', $name));
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @return array<string, list<string>>
     */
    private function scopeMap(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf('rasuvaeff/yii3-mcp %s must map a name to a list of scopes', $name));
        }

        $map = [];
        /** @var mixed $scopes */
        foreach ($value as $key => $scopes) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException(sprintf('rasuvaeff/yii3-mcp %s must map a name to a list of scopes', $name));
            }

            $map[$key] = $this->stringList($scopes, $name . '.' . $key);
        }

        return $map;
    }
}
