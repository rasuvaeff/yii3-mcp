<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResourceTemplate;

/**
 * Argument autocompletion (completion/complete): a prompt argument and a
 * resource-template variable, each with its own value list.
 */
final readonly class CompletionTool
{
    #[McpPrompt(name: 'review')]
    public function review(
        #[CompletionProvider(values: ['security', 'performance'])]
        string $focus,
    ): string {
        return 'Review with focus ' . $focus;
    }

    #[McpPrompt(name: 'secret-review')]
    public function secretReview(
        #[CompletionProvider(values: ['internal-codename'])]
        string $target,
    ): string {
        return 'Secret review of ' . $target;
    }

    #[McpResourceTemplate(uriTemplate: 'app://reports/{region}', name: 'report')]
    public function report(
        #[CompletionProvider(values: ['emea', 'apac'])]
        string $region,
    ): string {
        return 'Report for ' . $region;
    }
}
