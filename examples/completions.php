<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Completion\ProviderInterface;
use Mcp\Schema\Prompt;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Visibility\PromptVisibilityInterface;
use Yiisoft\Test\Support\Container\SimpleContainer;

require dirname(__DIR__) . '/vendor/autoload.php';

// Argument autocompletion (completion/complete): the client offers values as
// the user types a prompt argument or a resource-template variable.

enum Environment: string
{
    case Staging = 'staging';
    case Production = 'production';
}

// A provider class is resolved through the DI container, so it can query a
// repository, a feature-flag service, anything.
final readonly class RegionCompletionProvider implements ProviderInterface
{
    public function __construct(
        /** @var list<string> */
        private array $regions = ['emea', 'apac', 'latam'],
    ) {}

    /**
     * @return list<string>
     */
    #[\Override]
    public function getCompletions(string $currentValue): array
    {
        return array_values(array_filter(
            $this->regions,
            static fn(string $region): bool => str_starts_with($region, $currentValue),
        ));
    }
}

final readonly class ReportTools
{
    #[McpPrompt(name: 'review')]
    public function review(
        #[CompletionProvider(values: ['security', 'performance'])]
        string $focus,
        #[CompletionProvider(enum: Environment::class)]
        string $environment,
    ): string {
        return sprintf('Review %s with focus %s', $environment, $focus);
    }

    #[McpResourceTemplate(uriTemplate: 'app://reports/{region}', name: 'report')]
    public function report(
        #[CompletionProvider(provider: RegionCompletionProvider::class)]
        string $region,
    ): string {
        return 'Report for ' . $region;
    }

    #[McpPrompt(name: 'internal-audit')]
    public function internalAudit(
        #[CompletionProvider(values: ['unreleased-codename'])]
        string $target,
    ): string {
        return 'Audit ' . $target;
    }
}

// Visibility applies to completions too: a prompt this session cannot see must
// not complete its arguments either (that would leak both the values and the
// prompt's existence).
final readonly class HideInternalPrompts implements PromptVisibilityInterface
{
    #[\Override]
    public function isVisible(Prompt $prompt, ?SessionInterface $session): bool
    {
        return !str_starts_with($prompt->name, 'internal-');
    }
}

$server = (new McpServerFactory(
    container: new SimpleContainer([
        ReportTools::class => new ReportTools(),
        RegionCompletionProvider::class => new RegionCompletionProvider(),
    ]),
    sessionStore: new InMemorySessionStore(),
    name: 'completions-example',
    version: '1.0.0',
))->create([ReportTools::class], promptVisibility: new HideInternalPrompts());

$factory = new Psr17Factory();
$tester = new McpTester($server, $factory, $factory, $factory);

$complete = static function (array $ref, string $argument, string $value) use ($tester): string {
    try {
        $result = $tester->request('completion/complete', [
            'ref' => $ref,
            'argument' => ['name' => $argument, 'value' => $value],
        ]);

        return implode(', ', $result['completion']['values']) ?: '(none)';
    } catch (RuntimeException $failure) {
        return 'refused: ' . $failure->getMessage();
    }
};

// 1. a fixed value list
echo 'focus "se"      → ' . $complete(['type' => 'ref/prompt', 'name' => 'review'], 'focus', 'se') . "\n";

// 2. a backed enum
echo 'environment "p" → ' . $complete(['type' => 'ref/prompt', 'name' => 'review'], 'environment', 'p') . "\n";

// 3. a provider class resolved through the container, on a template variable
echo 'region "a"      → ' . $complete(['type' => 'ref/resource', 'uri' => 'app://reports/apac'], 'region', 'a') . "\n";

// 4. a hidden prompt completes nothing and is reported exactly like a missing one
echo 'hidden prompt   → ' . $complete(['type' => 'ref/prompt', 'name' => 'internal-audit'], 'target', 'unre') . "\n";
echo 'missing prompt  → ' . $complete(['type' => 'ref/prompt', 'name' => 'no-such-prompt'], 'target', 'unre') . "\n";
