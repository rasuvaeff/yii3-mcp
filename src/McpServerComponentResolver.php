<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Yii3Mcp\Apps\AppParamParser;
use Rasuvaeff\Yii3Mcp\Apps\McpAppsConfigurator;
use Rasuvaeff\Yii3Mcp\Interceptor\CachingToolCallInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\PromptGetInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\ResourceReadInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\ResponseSizeLimitInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\SessionBudgetInterceptor;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\DelegatedHeaderProviderInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\ExecutionIdentityProviderInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\HttpOperationExecutor;
use Rasuvaeff\Yii3Mcp\OpenApi\OpenApiServerConfigurator;
use Rasuvaeff\Yii3Mcp\OpenApi\OperationModifierInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Yii3Mcp\OpenApi\SpecLoader;
use Rasuvaeff\Yii3Mcp\Prompts\MarkdownPromptsConfigurator;
use Rasuvaeff\Yii3Mcp\Visibility\DeclarativeToolVisibility;
use Rasuvaeff\Yii3Mcp\Visibility\PromptVisibilityInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ResourceVisibilityInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;

/**
 * @internal
 */
final readonly class McpServerComponentResolver
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        private ContainerInterface $container,
        private array $params,
    ) {}

    public function resolve(): McpServerComponents
    {
        /** @var list<class-string> $tools */
        $tools = $this->params['tools'];
        /** @var array{spec_path: string, base_url: string, operations: list<string>, headers: array<string, string>, spec_headers?: array<string, string>, cache_ttl?: int, max_response_bytes?: int, opaque_errors?: bool, identity_provider?: class-string<ExecutionIdentityProviderInterface>|'', delegated_header_provider?: class-string<DelegatedHeaderProviderInterface>|'', safe_methods_only?: bool, tool_names?: array<string, string>, operation_modifier?: class-string<OperationModifierInterface>|'', dry_run?: list<string>} $openapi */
        $openapi = $this->params['openapi'];

        /** @var list<ServerConfiguratorInterface> $configurators */
        $configurators = [];

        /** @var array<string, int> $cacheTools */
        $cacheTools = $this->params['cache']['tools'] ?? [];

        // Resolve application services only when a configured feature needs them.
        $identityProviderClass = $openapi['identity_provider'] ?? '';
        $identityProviderNeeded = ($openapi['spec_path'] !== '' && $openapi['operations'] !== []) || $cacheTools !== [];
        /** @var ?ExecutionIdentityProviderInterface $identityProvider */
        $identityProvider = $identityProviderClass === '' || !$identityProviderNeeded
            ? null
            : $this->getService($identityProviderClass);

        /** @var string $promptsPath */
        $promptsPath = $this->params['prompts_path'] ?? '';

        if ($promptsPath !== '') {
            /** @var int $promptResultBytes */
            $promptResultBytes = $this->params['limits']['prompt_result_bytes'] ?? MarkdownPromptsConfigurator::DEFAULT_MAX_RESULT_BYTES;
            $configurators[] = new MarkdownPromptsConfigurator($promptsPath, maxResultBytes: $promptResultBytes);
        }

        if ($openapi['spec_path'] !== '' && $openapi['operations'] !== []) {
            $cacheTtl = $openapi['cache_ttl'] ?? 0;

            // Spec credentials and operation credentials deliberately have separate scopes.
            $spec = str_starts_with($openapi['spec_path'], 'http://') || str_starts_with($openapi['spec_path'], 'https://')
                ? (new SpecLoader(
                    httpClient: $this->getService(ClientInterface::class),
                    requestFactory: $this->getService(RequestFactoryInterface::class),
                    headers: $openapi['spec_headers'] ?? [],
                    cache: $cacheTtl > 0 ? $this->getService(CacheInterface::class) : null,
                    cacheTtl: $cacheTtl,
                ))->fromUrl($openapi['spec_path'])
                : SpecIndex::fromFile($openapi['spec_path']);

            $delegatedHeaderProviderClass = $openapi['delegated_header_provider'] ?? '';

            /** @var ?DelegatedHeaderProviderInterface $delegatedHeaderProvider */
            $delegatedHeaderProvider = $delegatedHeaderProviderClass === '' ? null : $this->getService($delegatedHeaderProviderClass);

            $operationModifierClass = $openapi['operation_modifier'] ?? '';
            /** @var ?OperationModifierInterface $operationModifier */
            $operationModifier = $operationModifierClass === '' ? null : $this->getService($operationModifierClass);

            $configurators[] = new OpenApiServerConfigurator(
                spec: $spec,
                executor: new HttpOperationExecutor(
                    httpClient: $this->getService(ClientInterface::class),
                    requestFactory: $this->getService(RequestFactoryInterface::class),
                    streamFactory: $this->getService(StreamFactoryInterface::class),
                    baseUrl: $openapi['base_url'],
                    defaultHeaders: $openapi['headers'],
                    identityProvider: $identityProvider,
                    delegatedHeaderProvider: $delegatedHeaderProvider,
                    maxResponseBytes: $openapi['max_response_bytes'] ?? HttpOperationExecutor::DEFAULT_MAX_RESPONSE_BYTES,
                    opaqueErrors: $openapi['opaque_errors'] ?? false,
                ),
                operations: $openapi['operations'],
                safeMethodsOnly: $openapi['safe_methods_only'] ?? false,
                toolNames: $openapi['tool_names'] ?? [],
                modifier: $operationModifier,
                dryRunOperations: $openapi['dry_run'] ?? [],
            );
        }

        /** @var array{enable?: bool, definitions?: list<array<string, mixed>>} $apps */
        $apps = $this->params['apps'] ?? [];
        $appDefinitions = $apps['definitions'] ?? [];

        if (($apps['enable'] ?? false) || $appDefinitions !== []) {
            $configurators[] = new McpAppsConfigurator(
                array_map(AppParamParser::parse(...), $appDefinitions),
            );
        }

        /** @var list<class-string<ServerConfiguratorInterface>> $configuratorClasses */
        $configuratorClasses = $this->params['configurators'] ?? [];

        foreach ($configuratorClasses as $configuratorClass) {
            $configurators[] = $this->getService($configuratorClass);
        }

        /** @var list<ToolCallInterceptorInterface> $interceptors */
        $interceptors = [];

        /** @var array{budget?: int} $session */
        $session = $this->params['session'] ?? [];
        $budget = $session['budget'] ?? 0;

        if ($budget > 0) {
            $interceptors[] = new SessionBudgetInterceptor($budget);
        }

        /** @var list<class-string<ToolCallInterceptorInterface>> $interceptorClasses */
        $interceptorClasses = $this->params['interceptors'] ?? [];

        foreach ($interceptorClasses as $interceptorClass) {
            $interceptors[] = $this->getService($interceptorClass);
        }

        if ($cacheTools !== []) {
            /** @var string $cacheNamespace */
            $cacheNamespace = $this->params['cache']['namespace'] ?? '';
            /** @var string $serverName */
            $serverName = $this->params['server_name'];
            $interceptors[] = new CachingToolCallInterceptor(
                $this->getService(CacheInterface::class),
                $cacheTools,
                namespace: $cacheNamespace === '' ? $serverName : $cacheNamespace,
                identityProvider: $identityProvider,
            );
        }

        /** @var int $maxResultBytes */
        $maxResultBytes = $this->params['limits']['tool_result_bytes'] ?? 0;

        if ($maxResultBytes > 0) {
            $interceptors[] = new ResponseSizeLimitInterceptor($maxResultBytes);
        }

        /** @var class-string<ToolVisibilityInterface>|'' $visibilityClass */
        $visibilityClass = $this->params['tool_visibility'] ?? '';
        /** @var array{deny?: list<string>, allow?: list<string>} $declarative */
        $declarative = $this->params['visibility'] ?? [];
        $deny = $declarative['deny'] ?? [];
        $allow = $declarative['allow'] ?? [];

        if ($visibilityClass !== '' && ($deny !== [] || $allow !== [])) {
            throw new LogicException('Configure either "tool_visibility" (a ToolVisibilityInterface class) or declarative "visibility" deny/allow lists, not both');
        }

        $visibility = null;

        if ($visibilityClass !== '') {
            /** @var ToolVisibilityInterface $visibility */
            $visibility = $this->getService($visibilityClass);
        } elseif ($deny !== [] || $allow !== []) {
            $visibility = new DeclarativeToolVisibility(deny: $deny, allow: $allow);
        }

        /** @var list<PromptGetInterceptorInterface> $promptInterceptors */
        $promptInterceptors = [];

        /** @var list<class-string<PromptGetInterceptorInterface>> $promptInterceptorClasses */
        $promptInterceptorClasses = $this->params['prompt_interceptors'] ?? [];

        foreach ($promptInterceptorClasses as $promptInterceptorClass) {
            $promptInterceptors[] = $this->getService($promptInterceptorClass);
        }

        /** @var list<ResourceReadInterceptorInterface> $resourceInterceptors */
        $resourceInterceptors = [];

        /** @var list<class-string<ResourceReadInterceptorInterface>> $resourceInterceptorClasses */
        $resourceInterceptorClasses = $this->params['resource_interceptors'] ?? [];

        foreach ($resourceInterceptorClasses as $resourceInterceptorClass) {
            $resourceInterceptors[] = $this->getService($resourceInterceptorClass);
        }

        /** @var class-string<PromptVisibilityInterface>|'' $promptVisibilityClass */
        $promptVisibilityClass = $this->params['prompt_visibility'] ?? '';
        /** @var PromptVisibilityInterface|null $promptVisibility */
        $promptVisibility = $promptVisibilityClass === '' ? null : $this->getService($promptVisibilityClass);

        /** @var class-string<ResourceVisibilityInterface>|'' $resourceVisibilityClass */
        $resourceVisibilityClass = $this->params['resource_visibility'] ?? '';
        /** @var ResourceVisibilityInterface|null $resourceVisibility */
        $resourceVisibility = $resourceVisibilityClass === '' ? null : $this->getService($resourceVisibilityClass);

        return new McpServerComponents(
            $tools,
            $configurators,
            $interceptors,
            $visibility,
            $promptInterceptors,
            $resourceInterceptors,
            $promptVisibility,
            $resourceVisibility,
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function getService(string $id): object
    {
        $service = $this->container->get($id);

        if (!$service instanceof $id) {
            throw new LogicException(sprintf('Service "%s" must be an instance of %s', $id, $id));
        }

        return $service;
    }
}
