<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\CompletionCompleteHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Subscription\NotificationBusInterface;
use Mcp\Server\Wire\CachePolicy;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rasuvaeff\Yii3Mcp\Exception\InvalidToolClassException;
use Rasuvaeff\Yii3Mcp\Interceptor\InterceptingReferenceHandler;
use Rasuvaeff\Yii3Mcp\Interceptor\PromptGetInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\ResourceReadInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolResultDecoratorInterface;
use Rasuvaeff\Yii3Mcp\Resource\ResourceUpdateNotifier;
use Rasuvaeff\Yii3Mcp\Visibility\FilteredCallToolHandler;
use Rasuvaeff\Yii3Mcp\Visibility\FilteredCompletionCompleteHandler;
use Rasuvaeff\Yii3Mcp\Visibility\FilteredListPromptsHandler;
use Rasuvaeff\Yii3Mcp\Visibility\FilteredListResourcesHandler;
use Rasuvaeff\Yii3Mcp\Visibility\FilteredListResourceTemplatesHandler;
use Rasuvaeff\Yii3Mcp\Visibility\FilteredListToolsHandler;
use Rasuvaeff\Yii3Mcp\Visibility\PromptVisibilityInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ResourceVisibilityInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Builds an SDK {@see Server} from a list of application tool classes.
 *
 * Capability methods are declared with the SDK's own attributes (#[McpTool],
 * #[McpResource], #[McpResourceTemplate], #[McpPrompt]) on public methods;
 * handlers are registered as [class, method] references, so instances are
 * resolved lazily through the DI container and receive their dependencies
 * the normal Yii3 way. Classes implementing ConditionalToolInterface may opt
 * out of registration at build time.
 *
 * @api
 */
final readonly class McpServerFactory
{
    /**
     * @param string $instructions free-form "how to use this server" text served in `initialize`; '' omits it
     * @param int $paginationLimit page size for every list method; applied to the SDK's handlers AND this
     *                             package's filtering ones, which must never page differently
     * @param ProtocolVersion|null $protocolVersion pins the `initialize` handshake to exactly this
     *                                              (handshake-era) revision; null negotiates —
     *                                              the client's revision when supported
     * @param SubscriptionManagerInterface|null $subscriptionManager backs resources/subscribe; pass the SAME
     *                                                               instance {@see ResourceUpdateNotifier} reads
     * @param bool $compactToolResults encode array/object tool results as compact JSON text
     *                                 instead of the SDK's pretty-printed one (~3x fewer bytes in
     *                                 the text an agent reads); `structuredContent` keeps being
     *                                 produced for array results
     * @param bool $modernEra also serve the stateless 2026-07-28 era on the same endpoint;
     *                        false answers its requests with "unsupported protocol version"
     * @param bool $headerValidation reject a stateless-era request whose standard headers
     *                               (Mcp-Method, Mcp-Name, Mcp-Param-*) contradict its body (-32020)
     * @param NotificationBusInterface|null $notificationBus feeds stateless `subscriptions/listen` streams; pass
     *                                                      the SAME instance {@see ResourceUpdateNotifier} publishes to;
     *                                                      null = streams acknowledge and carry nothing
     * @param float $subscriptionLifetime seconds a listen stream is held open (0 = until the client or runtime
     *                                    ends it); under PHP-FPM keep it below max_execution_time
     * @param string $requestStateKey signs the `requestState` a multi round-trip call carries between rounds
     *                                (at least 32 bytes; '' = none: a handler asking more than once per call fails)
     * @param int $requestStateTtl seconds a minted requestState stays valid
     * @param CachePolicy|null $cachePolicy SEP-2549 caching hints on stateless-era answers; null = the SDK
     *                                      default (nothing fresh, nothing shared). A `public` hint on a list
     *                                      or read a visibility filter makes per-caller fails the build.
     */
    public function __construct(
        private ContainerInterface $container,
        private SessionStoreInterface $sessionStore,
        private string $name = 'yii3-mcp',
        private string $version = 'dev',
        private ?LoggerInterface $logger = null,
        private string $instructions = '',
        private int $paginationLimit = self::DEFAULT_PAGINATION_LIMIT,
        private ?ProtocolVersion $protocolVersion = null,
        private ?SubscriptionManagerInterface $subscriptionManager = null,
        private bool $compactToolResults = false,
        private bool $modernEra = true,
        private bool $headerValidation = true,
        private ?NotificationBusInterface $notificationBus = null,
        private float $subscriptionLifetime = self::DEFAULT_SUBSCRIPTION_LIFETIME,
        #[\SensitiveParameter]
        private string $requestStateKey = '',
        private int $requestStateTtl = 600,
        private ?CachePolicy $cachePolicy = null,
    ) {
        if ($paginationLimit < 1) {
            throw new \InvalidArgumentException(sprintf('Pagination limit must be at least 1, %d given', $paginationLimit));
        }
    }

    /**
     * Matches the SDK's own default; kept here so the filtering list handlers
     * and the SDK's pagination cannot drift apart silently.
     */
    public const int DEFAULT_PAGINATION_LIMIT = 50;

    /** The SDK's own ceiling for a subscriptions/listen stream, in seconds */
    public const float DEFAULT_SUBSCRIPTION_LIFETIME = 30.0;

    /** Result-JSON knob values for the `result_json` param ({@see self::__construct()}) */
    public const string RESULT_JSON_PRETTY = 'pretty';
    public const string RESULT_JSON_COMPACT = 'compact';

    /**
     * @param list<class-string> $toolClasses
     * @param iterable<ServerConfiguratorInterface> $configurators
     * @param iterable<ToolCallInterceptorInterface> $interceptors tool-call chain, first = outermost
     * @param ToolVisibilityInterface|null $toolVisibility per-session filter for tools/list + fail-closed tools/call
     * @param iterable<PromptGetInterceptorInterface> $promptInterceptors prompts/get chain, first = outermost
     * @param iterable<ResourceReadInterceptorInterface> $resourceInterceptors resources/read chain (static + templates), first = outermost
     * @param PromptVisibilityInterface|null $promptVisibility per-session filter for prompts/list + fail-closed prompts/get
     * @param ResourceVisibilityInterface|null $resourceVisibility per-session filter for resources/list, resources/templates/list + fail-closed resources/read
     * @param iterable<ToolResultDecoratorInterface> $resultDecorators applied in order to the formatted result of every
     *                                                                 successful tools/call, after the whole interceptor chain
     */
    public function create(
        array $toolClasses,
        iterable $configurators = [],
        iterable $interceptors = [],
        ?ToolVisibilityInterface $toolVisibility = null,
        iterable $promptInterceptors = [],
        iterable $resourceInterceptors = [],
        ?PromptVisibilityInterface $promptVisibility = null,
        ?ResourceVisibilityInterface $resourceVisibility = null,
        iterable $resultDecorators = [],
    ): Server {
        $builder = Server::builder()
            ->setServerInfo(name: $this->name, version: $this->version)
            ->setContainer($this->container)
            ->setSession(sessionStore: $this->sessionStore)
            ->setPaginationLimit($this->paginationLimit);

        if (!$this->modernEra) {
            $builder->withoutModernEra();
        }

        $builder
            ->setHeaderValidator($this->headerValidation)
            ->setSubscriptionLifetime($this->subscriptionLifetime);

        if ($this->notificationBus instanceof NotificationBusInterface) {
            $builder->setNotificationBus($this->notificationBus);
        }

        if ($this->requestStateKey !== '') {
            $builder->setRequestState($this->requestStateKey, $this->requestStateTtl);
        }

        if ($this->cachePolicy instanceof CachePolicy) {
            $builder->setCachePolicy($this->cachePolicy);
        }

        if ($this->instructions !== '') {
            $builder->setInstructions($this->instructions);
        }

        if ($this->protocolVersion instanceof ProtocolVersion) {
            $builder->setProtocolVersion($this->protocolVersion);
        }

        // the subscribe/unsubscribe handlers and ResourceUpdateNotifier must
        // read the same subscription state, so a swapped-in manager has to
        // reach the builder too
        if ($this->subscriptionManager instanceof SubscriptionManagerInterface) {
            $builder->setResourceSubscriptionManager($this->subscriptionManager);
        }

        if ($this->logger instanceof LoggerInterface) {
            $builder->setLogger($this->logger);
        }

        $attributeToolNames = [];

        foreach ($toolClasses as $class) {
            $this->register($builder, $class, $attributeToolNames);
        }

        foreach ($configurators as $configurator) {
            if ($configurator instanceof ReservedToolNamesAwareInterface) {
                $configurator = $configurator->withReservedToolNames($attributeToolNames);
            }

            $configurator->configure($builder);
        }

        $interceptorList = [];

        foreach ($interceptors as $interceptor) {
            $interceptorList[] = $interceptor;
        }

        $promptInterceptorList = [];

        foreach ($promptInterceptors as $promptInterceptor) {
            $promptInterceptorList[] = $promptInterceptor;
        }

        $resourceInterceptorList = [];

        foreach ($resourceInterceptors as $resourceInterceptor) {
            $resourceInterceptorList[] = $resourceInterceptor;
        }

        $resultDecoratorList = [];

        foreach ($resultDecorators as $resultDecorator) {
            $resultDecoratorList[] = $resultDecorator;
        }

        $anyVisibility = $toolVisibility instanceof ToolVisibilityInterface
            || $promptVisibility instanceof PromptVisibilityInterface
            || $resourceVisibility instanceof ResourceVisibilityInterface;

        // a visibility filter makes lists and reads per caller: a shared cache
        // keeping one caller's view would serve it to the next
        $publicPerCaller = $anyVisibility && $this->cachePolicy instanceof CachePolicy
            ? CachePolicyParams::publiclyCachedCallerSpecificMethods($this->cachePolicy)
            : [];

        if ($publicPerCaller !== []) {
            throw new \LogicException(sprintf(
                'cache_policy marks %s as "public" while a visibility filter makes them per caller; use "private"',
                implode(', ', $publicPerCaller),
            ));
        }

        $referenceHandler = null;

        if ($interceptorList !== [] || $promptInterceptorList !== [] || $resourceInterceptorList !== [] || $anyVisibility || $this->compactToolResults || $resultDecoratorList !== []) {
            // the decorator wraps EVERY registration path: [class, method]
            // references, closures and explicit handler objects all execute
            // through the reference handler
            $referenceHandler = new InterceptingReferenceHandler(
                inner: new ReferenceHandler($this->container),
                interceptors: $interceptorList,
                visibility: $toolVisibility,
                promptInterceptors: $promptInterceptorList,
                resourceInterceptors: $resourceInterceptorList,
                promptVisibility: $promptVisibility,
                resourceVisibility: $resourceVisibility,
                compactToolResults: $this->compactToolResults,
                resultDecorators: $resultDecoratorList,
            );
            $builder->setReferenceHandler($referenceHandler);
        }

        // every server gets its own registry wrapped in the duplicate guard:
        // the SDK registry is last-write-wins, so a name collision between ANY
        // two registration paths (attribute tools, configurators, the OpenAPI
        // bridge, Markdown prompts) would silently drop one handler while
        // name-keyed rules (visibility, cache, RBAC, audit) keep matching —
        // the guard turns that into a build-time DuplicateCapabilityException
        $registry = new GuardedRegistry(new Registry(logger: $this->logger ?? new NullLogger()));
        $builder->setRegistry($registry);

        if ($anyVisibility) {
            // owning the registry lets the filtering list handlers read it;
            // custom request handlers run ahead of the SDK's own
            if ($toolVisibility instanceof ToolVisibilityInterface) {
                /** @var RequestHandlerInterface<mixed> $listHandler */
                $listHandler = new FilteredListToolsHandler(
                    registry: $registry,
                    visibility: $toolVisibility,
                    pageSize: $this->paginationLimit,
                );
                $builder->addRequestHandler($listHandler);

                // a hidden tool must answer exactly like a missing one,
                // before the SDK validates arguments against its schema
                if ($referenceHandler instanceof InterceptingReferenceHandler) {
                    /** @var RequestHandlerInterface<mixed> $callHandler */
                    $callHandler = new FilteredCallToolHandler(
                        registry: $registry,
                        inner: new CallToolHandler($registry, $referenceHandler, $this->logger ?? new NullLogger()),
                        visibility: $toolVisibility,
                    );
                    $builder->addRequestHandler($callHandler);
                }
            }

            if ($promptVisibility instanceof PromptVisibilityInterface) {
                /** @var RequestHandlerInterface<mixed> $listHandler */
                $listHandler = new FilteredListPromptsHandler(
                    registry: $registry,
                    visibility: $promptVisibility,
                    pageSize: $this->paginationLimit,
                );
                $builder->addRequestHandler($listHandler);
            }

            if ($resourceVisibility instanceof ResourceVisibilityInterface) {
                /** @var RequestHandlerInterface<mixed> $listHandler */
                $listHandler = new FilteredListResourcesHandler(
                    registry: $registry,
                    visibility: $resourceVisibility,
                    pageSize: $this->paginationLimit,
                );
                $builder->addRequestHandler($listHandler);
                /** @var RequestHandlerInterface<mixed> $templatesHandler */
                $templatesHandler = new FilteredListResourceTemplatesHandler(
                    registry: $registry,
                    visibility: $resourceVisibility,
                    pageSize: $this->paginationLimit,
                );
                $builder->addRequestHandler($templatesHandler);
            }

            // completion/complete is the one capability call the SDK serves
            // straight off the registry, bypassing the reference handler — so
            // neither visibility nor the interceptor chains reach it. Without
            // this decorator a hidden prompt still completes its arguments.
            if ($promptVisibility instanceof PromptVisibilityInterface || $resourceVisibility instanceof ResourceVisibilityInterface) {
                /** @var RequestHandlerInterface<mixed> $completionHandler */
                $completionHandler = new FilteredCompletionCompleteHandler(
                    registry: $registry,
                    inner: new CompletionCompleteHandler($registry, $this->container),
                    promptVisibility: $promptVisibility,
                    resourceVisibility: $resourceVisibility,
                );
                $builder->addRequestHandler($completionHandler);
            }
        }

        return $builder->build();
    }

    /**
     * @param class-string $class
     * @param list<string> $toolNames names of the registered #[McpTool] methods, appended in place
     */
    private function register(Builder $builder, string $class, array &$toolNames): void
    {
        if (!class_exists($class)) {
            throw new InvalidToolClassException(sprintf('Tool class "%s" does not exist', $class));
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->implementsInterface(ConditionalToolInterface::class)) {
            /** @var ConditionalToolInterface $instance */
            $instance = $this->container->get($class);

            if (!$instance->shouldRegister()) {
                return;
            }
        }

        $registered = 0;

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
                continue;
            }

            $registered += $this->registerMethod($builder, $class, $method, $toolNames);
        }

        if ($registered === 0) {
            throw new InvalidToolClassException(sprintf('Tool class "%s" has no public methods with MCP capability attributes (#[McpTool], #[McpResource], #[McpResourceTemplate], #[McpPrompt])', $class));
        }
    }

    /**
     * @param class-string $class
     * @param list<string> $toolNames
     */
    private function registerMethod(Builder $builder, string $class, ReflectionMethod $method, array &$toolNames): int
    {
        $registered = 0;

        foreach ($method->getAttributes(McpTool::class) as $attribute) {
            $tool = $attribute->newInstance();
            // the SDK derives the served name inside its reflected loader;
            // mirror the rule here so configurators can be told which names
            // are taken before they register anything
            $toolNames[] = $tool->name ?? ($method->getName() === '__invoke'
                ? $method->getDeclaringClass()->getShortName()
                : $method->getName());
            $builder->addTool(
                handler: [$class, $method->getName()],
                name: $tool->name,
                title: $tool->title,
                description: $tool->description,
                annotations: $tool->annotations,
                icons: $tool->icons,
                meta: $tool->meta,
                outputSchema: $tool->outputSchema,
            );
            ++$registered;
        }

        foreach ($method->getAttributes(McpResource::class) as $attribute) {
            $resource = $attribute->newInstance();
            $builder->addResource(
                handler: [$class, $method->getName()],
                uri: $resource->uri,
                name: $resource->name,
                title: $resource->title,
                description: $resource->description,
                mimeType: $resource->mimeType,
                size: $resource->size,
                annotations: $resource->annotations,
                icons: $resource->icons,
                meta: $resource->meta,
            );
            ++$registered;
        }

        foreach ($method->getAttributes(McpResourceTemplate::class) as $attribute) {
            $template = $attribute->newInstance();
            $builder->addResourceTemplate(
                handler: [$class, $method->getName()],
                uriTemplate: $template->uriTemplate,
                name: $template->name,
                title: $template->title,
                description: $template->description,
                mimeType: $template->mimeType,
                annotations: $template->annotations,
                meta: $template->meta,
            );
            ++$registered;
        }

        foreach ($method->getAttributes(McpPrompt::class) as $attribute) {
            $prompt = $attribute->newInstance();
            $builder->addPrompt(
                handler: [$class, $method->getName()],
                name: $prompt->name,
                title: $prompt->title,
                description: $prompt->description,
                icons: $prompt->icons,
                meta: $prompt->meta,
            );
            ++$registered;
        }

        return $registered;
    }
}
