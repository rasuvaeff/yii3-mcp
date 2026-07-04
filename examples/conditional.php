<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\ConditionalToolInterface;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Yiisoft\Test\Support\Container\SimpleContainer;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class StableTools
{
    /**
     * Returns the application status.
     */
    #[McpTool(name: 'app.status')]
    public function status(): string
    {
        return 'ok';
    }
}

// The class opts out of registration at build time: the instance is resolved
// through the container (inject FeatureFlags etc. via the constructor) and
// skipped entirely when shouldRegister() returns false.
final readonly class BetaTools implements ConditionalToolInterface
{
    public function __construct(private bool $enabled) {}

    #[\Override]
    public function shouldRegister(): bool
    {
        return $this->enabled;
    }

    #[McpTool(name: 'beta.op')]
    public function betaOp(): string
    {
        return 'beta';
    }
}

$factory = new Psr17Factory();

foreach ([false, true] as $betaEnabled) {
    $server = (new McpServerFactory(
        container: new SimpleContainer([
            StableTools::class => new StableTools(),
            BetaTools::class => new BetaTools(enabled: $betaEnabled),
        ]),
        sessionStore: new InMemorySessionStore(),
        name: 'conditional-example',
        version: '1.0.0',
    ))->create([StableTools::class, BetaTools::class]);

    $names = array_column((new McpTester($server, $factory, $factory, $factory))->listTools(), 'name');
    echo 'beta ' . ($betaEnabled ? 'enabled ' : 'disabled') . ' -> tools: ' . implode(', ', $names) . "\n";
}
