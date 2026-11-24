<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use LogicException;
use Psr\Container\ContainerInterface;
use Rasuvaeff\Mcp\Configuration\ServerComponentResolver;
use Rasuvaeff\Mcp\McpServerComponents;
use Rasuvaeff\Mcp\OpenApi\OpenApiConfiguratorResolver;
use Rasuvaeff\Mcp\OpenApi\OpenApiServerConfigurator;

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
        if (($this->params['prompts_path'] ?? '') !== '') {
            throw new LogicException('"prompts_path" was removed in 6.0 together with Markdown prompts; register prompts with #[McpPrompt] or a ServerConfiguratorInterface');
        }

        /** @var array<string, mixed> $openapi */
        $openapi = $this->params['openapi'];
        $bridge = (new OpenApiConfiguratorResolver($this->container, $openapi))->resolve();

        /** @var array{budget?: int} $session */
        $session = $this->params['session'] ?? [];

        // the key moved in 4.0; silently ignoring an application still
        // setting it would switch its anti-loop guard off
        if (array_key_exists('budget', $session)) {
            throw new LogicException('"session.budget" moved to "tool_call_budget.calls" in 4.0 (it now applies to the stateless protocol era too)');
        }

        return (new ServerComponentResolver($this->container, $this->params))->resolve($bridge instanceof OpenApiServerConfigurator ? [$bridge] : []);
    }
}
