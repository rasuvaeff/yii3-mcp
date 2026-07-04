<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Rasuvaeff\Yii3Mcp\Exception\InvalidToolClassException;
use Rasuvaeff\Yii3Mcp\Interceptor\InterceptingReferenceHandler;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;
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

    /**
     * @param list<class-string> $toolClasses
     * @param iterable<ServerConfiguratorInterface> $configurators
     * @param iterable<ToolCallInterceptorInterface> $interceptors tool-call chain, first = outermost
     */
    public function create(array $toolClasses, iterable $configurators = [], iterable $interceptors = []): Server
    {
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

        if ($interceptorList !== []) {
            // the interceptor chain wraps EVERY registration path: [class, method]
            // references, closures and explicit ToolHandlerInterface handlers all
            // execute through the reference handler
            $builder->setReferenceHandler(new InterceptingReferenceHandler(
                inner: new ReferenceHandler($this->container),
                interceptors: $interceptorList,
            ));
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
