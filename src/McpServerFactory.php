<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rasuvaeff\Yii3Mcp\Exception\InvalidToolClassException;
use Rasuvaeff\Yii3Mcp\Interceptor\InterceptingReferenceHandler;
use Rasuvaeff\Yii3Mcp\Interceptor\PromptGetInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\ResourceReadInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
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
    public function __construct(
        private ContainerInterface $container,
        private SessionStoreInterface $sessionStore,
        private string $name = 'yii3-mcp',
        private string $version = 'dev',
        private ?LoggerInterface $logger = null,
    ) {}

    private const int PAGE_SIZE = 50;

    /**
     * @param list<class-string> $toolClasses
     * @param iterable<ServerConfiguratorInterface> $configurators
     * @param iterable<ToolCallInterceptorInterface> $interceptors tool-call chain, first = outermost
     * @param ToolVisibilityInterface|null $toolVisibility per-session filter for tools/list + fail-closed tools/call
     * @param iterable<PromptGetInterceptorInterface> $promptInterceptors prompts/get chain, first = outermost
     * @param iterable<ResourceReadInterceptorInterface> $resourceInterceptors resources/read chain (static + templates), first = outermost
     * @param PromptVisibilityInterface|null $promptVisibility per-session filter for prompts/list + fail-closed prompts/get
     * @param ResourceVisibilityInterface|null $resourceVisibility per-session filter for resources/list, resources/templates/list + fail-closed resources/read
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
    ): Server {
        $builder = Server::builder()
            ->setServerInfo(name: $this->name, version: $this->version)
            ->setContainer($this->container)
            ->setSession(sessionStore: $this->sessionStore);

        if ($this->logger instanceof LoggerInterface) {
            $builder->setLogger($this->logger);
        }

        foreach ($toolClasses as $class) {
            $this->register($builder, $class);
        }

        foreach ($configurators as $configurator) {
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

        $anyVisibility = $toolVisibility instanceof ToolVisibilityInterface
            || $promptVisibility instanceof PromptVisibilityInterface
            || $resourceVisibility instanceof ResourceVisibilityInterface;

        if ($interceptorList !== [] || $promptInterceptorList !== [] || $resourceInterceptorList !== [] || $anyVisibility) {
            // the decorator wraps EVERY registration path: [class, method]
            // references, closures and explicit handler objects all execute
            // through the reference handler
            $builder->setReferenceHandler(new InterceptingReferenceHandler(
                inner: new ReferenceHandler($this->container),
                interceptors: $interceptorList,
                visibility: $toolVisibility,
                promptInterceptors: $promptInterceptorList,
                resourceInterceptors: $resourceInterceptorList,
                promptVisibility: $promptVisibility,
                resourceVisibility: $resourceVisibility,
            ));
        }

        if ($anyVisibility) {
            // owning the registry lets the filtering list handlers read it;
            // custom request handlers run ahead of the SDK's own
            $registry = new Registry(logger: $this->logger ?? new NullLogger());
            $builder->setRegistry($registry);

            if ($toolVisibility instanceof ToolVisibilityInterface) {
                /** @var \Mcp\Server\Handler\Request\RequestHandlerInterface<mixed> $listHandler */
                $listHandler = new FilteredListToolsHandler(
                    registry: $registry,
                    visibility: $toolVisibility,
                    pageSize: self::PAGE_SIZE,
                );
                $builder->addRequestHandler($listHandler);
            }

            if ($promptVisibility instanceof PromptVisibilityInterface) {
                /** @var \Mcp\Server\Handler\Request\RequestHandlerInterface<mixed> $listHandler */
                $listHandler = new FilteredListPromptsHandler(
                    registry: $registry,
                    visibility: $promptVisibility,
                    pageSize: self::PAGE_SIZE,
                );
                $builder->addRequestHandler($listHandler);
            }

            if ($resourceVisibility instanceof ResourceVisibilityInterface) {
                /** @var \Mcp\Server\Handler\Request\RequestHandlerInterface<mixed> $listHandler */
                $listHandler = new FilteredListResourcesHandler(
                    registry: $registry,
                    visibility: $resourceVisibility,
                    pageSize: self::PAGE_SIZE,
                );
                $builder->addRequestHandler($listHandler);
                /** @var \Mcp\Server\Handler\Request\RequestHandlerInterface<mixed> $templatesHandler */
                $templatesHandler = new FilteredListResourceTemplatesHandler(
                    registry: $registry,
                    visibility: $resourceVisibility,
                    pageSize: self::PAGE_SIZE,
                );
                $builder->addRequestHandler($templatesHandler);
            }
        }

        return $builder->build();
    }

    /**
     * @param class-string $class
     */
    private function register(Builder $builder, string $class): void
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

            $registered += $this->registerMethod($builder, $class, $method);
        }

        if ($registered === 0) {
            throw new InvalidToolClassException(sprintf('Tool class "%s" has no public methods with MCP capability attributes (#[McpTool], #[McpResource], #[McpResourceTemplate], #[McpPrompt])', $class));
        }
    }

    /**
     * @param class-string $class
     */
    private function registerMethod(Builder $builder, string $class, ReflectionMethod $method): int
    {
        $registered = 0;

        foreach ($method->getAttributes(McpTool::class) as $attribute) {
            $tool = $attribute->newInstance();
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
