<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Visibility;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\CompletionTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyPromptVisibility;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyResourceVisibility;
use Rasuvaeff\Yii3Mcp\Visibility\FilteredCompletionCompleteHandler;
use Rasuvaeff\Yii3Mcp\Visibility\PromptVisibilityInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ResourceVisibilityInterface;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(FilteredCompletionCompleteHandler::class)]
final class FilteredCompletionCompleteHandlerTest
{
    public function visiblePromptStillCompletesItsArguments(): void
    {
        $result = $this->complete(
            $this->tester(promptVisibility: new DenyPromptVisibility(['secret-review'])),
            ['type' => 'ref/prompt', 'name' => 'review'],
            'focus',
            'se',
        );

        Assert::same($result['completion']['values'] ?? null, ['security']);
    }

    public function hiddenPromptDoesNotCompleteItsArguments(): void
    {
        $caught = $this->completionFailure(
            $this->tester(promptVisibility: new DenyPromptVisibility(['secret-review'])),
            ['type' => 'ref/prompt', 'name' => 'secret-review'],
            'target',
            'internal',
        );

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('Prompt not found: "secret-review"');
    }

    /**
     * A hidden prompt must be indistinguishable from a missing one — otherwise
     * completion/complete becomes an existence oracle for capabilities the
     * session may not see.
     */
    public function aHiddenPromptIsReportedExactlyLikeAMissingOne(): void
    {
        $tester = $this->tester(promptVisibility: new DenyPromptVisibility(['secret-review']));

        $hidden = $this->completionFailure($tester, ['type' => 'ref/prompt', 'name' => 'secret-review'], 'target', 'x');
        $missing = $this->completionFailure($tester, ['type' => 'ref/prompt', 'name' => 'no-such-prompt'], 'target', 'x');

        Assert::notNull($hidden);
        Assert::notNull($missing);
        Assert::same(
            str_replace('secret-review', 'no-such-prompt', $hidden->getMessage()),
            $missing->getMessage(),
        );
    }

    public function hiddenResourceTemplateDoesNotCompleteItsVariables(): void
    {
        $caught = $this->completionFailure(
            $this->tester(resourceVisibility: new DenyResourceVisibility(hiddenTemplates: ['app://reports/{region}'])),
            ['type' => 'ref/resource', 'uri' => 'app://reports/emea'],
            'region',
            'em',
        );

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('Resource not found for uri: "app://reports/emea"');
    }

    public function visibleResourceTemplateStillCompletesItsVariables(): void
    {
        $result = $this->complete(
            $this->tester(resourceVisibility: new DenyResourceVisibility(hiddenTemplates: ['app://other/{id}'])),
            ['type' => 'ref/resource', 'uri' => 'app://reports/emea'],
            'region',
            'em',
        );

        Assert::same($result['completion']['values'] ?? null, ['emea']);
    }

    /**
     * Prompt visibility alone must not start filtering resources (and vice
     * versa) — each ref kind is decided by its own configured filter.
     */
    public function promptVisibilityAloneLeavesResourceCompletionsAlone(): void
    {
        $result = $this->complete(
            $this->tester(promptVisibility: new DenyPromptVisibility(['secret-review'])),
            ['type' => 'ref/resource', 'uri' => 'app://reports/emea'],
            'region',
            'ap',
        );

        Assert::same($result['completion']['values'] ?? null, ['apac']);
    }

    public function withoutAnyVisibilityCompletionIsUntouched(): void
    {
        $result = $this->complete(
            $this->tester(),
            ['type' => 'ref/prompt', 'name' => 'secret-review'],
            'target',
            'internal',
        );

        Assert::same($result['completion']['values'] ?? null, ['internal-codename']);
    }

    public function anUnknownArgumentStillCompletesToNothing(): void
    {
        $result = $this->complete(
            $this->tester(promptVisibility: new DenyPromptVisibility(['secret-review'])),
            ['type' => 'ref/prompt', 'name' => 'review'],
            'no-such-argument',
            'x',
        );

        Assert::same($result['completion']['values'] ?? null, []);
    }

    /**
     * @param array<string, string> $ref
     *
     * @return array<array-key, mixed>
     */
    private function complete(McpTester $tester, array $ref, string $argument, string $value): array
    {
        return $tester->request('completion/complete', [
            'ref' => $ref,
            'argument' => ['name' => $argument, 'value' => $value],
        ]);
    }

    /**
     * @param array<string, string> $ref
     */
    private function completionFailure(McpTester $tester, array $ref, string $argument, string $value): ?RuntimeException
    {
        $caught = null;

        try {
            $this->complete($tester, $ref, $argument, $value);
        } catch (RuntimeException $caught) {
        }

        return $caught;
    }

    private function tester(
        ?PromptVisibilityInterface $promptVisibility = null,
        ?ResourceVisibilityInterface $resourceVisibility = null,
    ): McpTester {
        $factory = new Psr17Factory();

        return new McpTester(
            server: $this->server($promptVisibility, $resourceVisibility),
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    private function server(
        ?PromptVisibilityInterface $promptVisibility,
        ?ResourceVisibilityInterface $resourceVisibility,
    ): Server {
        return (new McpServerFactory(
            container: new SimpleContainer([CompletionTool::class => new CompletionTool()]),
            sessionStore: new InMemorySessionStore(),
            name: 'completion-suite',
            version: '1.0.0',
        ))->create(
            [CompletionTool::class],
            promptVisibility: $promptVisibility,
            resourceVisibility: $resourceVisibility,
        );
    }
}
