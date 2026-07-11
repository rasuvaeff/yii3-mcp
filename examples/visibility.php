<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Tool;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Visibility\DeclarativeToolVisibility;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;
use Yiisoft\Test\Support\Container\SimpleContainer;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class MixedTools
{
    /**
     * Returns public catalog data.
     */
    #[McpTool(name: 'catalog.list')]
    public function catalog(): string
    {
        return 'catalog';
    }

    /**
     * Deletes a record — admin clients only.
     */
    #[McpTool(name: 'admin.delete')]
    public function delete(string $id): string
    {
        return 'deleted ' . $id;
    }
}

// Per-session visibility: which tools THIS session sees and may call.
// Decided from session data (client_info here; a tenant id works the same).
// In an application: params 'tool_visibility' => AdminOnlyVisibility::class.
final readonly class AdminOnlyVisibility implements ToolVisibilityInterface
{
    #[\Override]
    public function isVisible(Tool $tool, ?SessionInterface $session): bool
    {
        if (!str_starts_with($tool->name, 'admin.')) {
            return true;
        }

        $info = $session?->get('client_info');

        return is_array($info) && str_starts_with((string) ($info['name'] ?? ''), 'admin-');
    }
}

$factory = new Psr17Factory();
$server = (new McpServerFactory(
    container: new SimpleContainer([MixedTools::class => new MixedTools()]),
    sessionStore: new InMemorySessionStore(),
    name: 'visibility-example',
    version: '1.0.0',
))->create([MixedTools::class], [], [], new AdminOnlyVisibility());

// McpTester introduces itself as "mcp-tester" — not an admin client:
$tester = new McpTester($server, $factory, $factory, $factory);

$names = array_column($tester->listTools(), 'name');
echo 'tools/list for non-admin: ' . implode(', ', $names) . "\n";

// fail-closed: guessing the hidden name does not help
$result = $tester->callTool('admin.delete', ['id' => '42']);
echo 'admin.delete isError=' . var_export($result['isError'], true) . ': ' . $result['content'][0]['text'] . "\n";

// The declarative variant: session-independent deny/allow name patterns with
// '*' wildcards — no class needed. In an application:
// params 'visibility' => ['deny' => ['admin.*'], 'allow' => []].
$declarativeServer = (new McpServerFactory(
    container: new SimpleContainer([MixedTools::class => new MixedTools()]),
    sessionStore: new InMemorySessionStore(),
    name: 'declarative-visibility-example',
    version: '1.0.0',
))->create([MixedTools::class], [], [], new DeclarativeToolVisibility(deny: ['admin.*']));

$declarativeTester = new McpTester($declarativeServer, $factory, $factory, $factory);
echo 'tools/list with deny admin.*: ' . implode(', ', array_column($declarativeTester->listTools(), 'name')) . "\n";
