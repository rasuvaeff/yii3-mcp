<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Exception\ResourceReadException;
use Mcp\Schema\Prompt;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\Interceptor\CallOutcome;
use Rasuvaeff\Yii3Mcp\Interceptor\PromptGetContext;
use Rasuvaeff\Yii3Mcp\Interceptor\PromptGetInterceptorInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\ResourceReadContext;
use Rasuvaeff\Yii3Mcp\Interceptor\ResourceReadInterceptorInterface;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Visibility\PromptVisibilityInterface;
use Yiisoft\Test\Support\Container\SimpleContainer;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class KnowledgeBase
{
    /**
     * Writing-style guidelines.
     */
    #[McpPrompt(name: 'style-guide')]
    public function styleGuide(): array
    {
        return ['user' => 'Write short sentences.'];
    }

    /**
     * Internal escalation playbook — hidden below.
     */
    #[McpPrompt(name: 'internal-playbook')]
    public function playbook(): array
    {
        return ['user' => 'Escalate to on-call.'];
    }

    #[McpResource(uri: 'kb://public/faq', name: 'faq', mimeType: 'text/plain')]
    public function faq(): string
    {
        return 'Q: is it fast? A: yes.';
    }

    #[McpResourceTemplate(uriTemplate: 'kb://articles/{slug}', name: 'article', mimeType: 'text/plain')]
    public function article(string $slug): string
    {
        return 'article: ' . $slug;
    }
}

// prompts/get chain — audit who fetched which prompt. In an application:
// params 'prompt_interceptors' => [PromptAudit::class].
final class PromptAudit implements PromptGetInterceptorInterface
{
    #[\Override]
    public function intercept(PromptGetContext $context, callable $next): mixed
    {
        try {
            /** @var mixed $result */
            $result = $next();
        } catch (Throwable $exception) {
            echo sprintf("prompt %s -> %s\n", $context->promptName, CallOutcome::fromThrowable($exception)->value);

            throw $exception;
        }

        echo sprintf("prompt %s -> %s\n", $context->promptName, CallOutcome::Success->value);

        return $result;
    }
}

// resources/read chain — an ACL on template variables: article slugs
// starting with "draft-" are rejected WITH a client-visible reason.
// In an application: params 'resource_interceptors' => [DraftGate::class].
final class DraftGate implements ResourceReadInterceptorInterface
{
    #[\Override]
    public function intercept(ResourceReadContext $context, callable $next): mixed
    {
        $slug = $context->variables['slug'] ?? null;

        if (is_string($slug) && str_starts_with($slug, 'draft-')) {
            throw new ResourceReadException(sprintf('Draft "%s" is not published yet', $slug));
        }

        echo sprintf("read %s (template: %s)\n", $context->uri, $context->uriTemplate ?? 'static');

        return $next();
    }
}

// Prompt visibility — the internal playbook is invisible AND unfetchable
// (reported as not found). In an application: params 'prompt_visibility'.
final readonly class PublicPromptsOnly implements PromptVisibilityInterface
{
    #[\Override]
    public function isVisible(Prompt $prompt, ?SessionInterface $session): bool
    {
        return !str_starts_with($prompt->name, 'internal-');
    }
}

$factory = new Psr17Factory();
$server = (new McpServerFactory(
    container: new SimpleContainer([KnowledgeBase::class => new KnowledgeBase()]),
    sessionStore: new InMemorySessionStore(),
    name: 'capability-hooks-example',
    version: '1.0.0',
))->create(
    [KnowledgeBase::class],
    promptInterceptors: [new PromptAudit()],
    resourceInterceptors: [new DraftGate()],
    promptVisibility: new PublicPromptsOnly(),
);

$tester = new McpTester($server, $factory, $factory, $factory);

echo 'prompts/list: ' . implode(', ', array_column($tester->listPrompts(), 'name')) . "\n";

$tester->request('prompts/get', ['name' => 'style-guide']);

try {
    $tester->request('prompts/get', ['name' => 'internal-playbook']);
} catch (RuntimeException $exception) {
    echo 'hidden prompt: ' . $exception->getMessage() . "\n";
}

$tester->readResource('kb://public/faq');
$tester->readResource('kb://articles/hello-world');

try {
    $tester->readResource('kb://articles/draft-secret');
} catch (RuntimeException $exception) {
    echo 'rejected read: ' . $exception->getMessage() . "\n";
}
