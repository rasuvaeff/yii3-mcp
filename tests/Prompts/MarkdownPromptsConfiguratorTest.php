<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Prompts;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Prompts\Exception\InvalidPromptFileException;
use Rasuvaeff\Yii3Mcp\Prompts\MarkdownPromptsConfigurator;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(MarkdownPromptsConfigurator::class)]
final class MarkdownPromptsConfiguratorTest
{
    public function listExposesPromptsWithMetadata(): void
    {
        $prompts = $this->tester()->request('prompts/list')['prompts'] ?? [];
        $byName = array_column(array_filter((array) $prompts, is_array(...)), null, 'name');

        Assert::same($byName['code-review']['title'] ?? null, 'Code review assistant');
        Assert::same($byName['code-review']['description'] ?? null, 'Reviews a diff with a given focus');
        Assert::same($byName['code-review']['arguments'] ?? null, [
            ['name' => 'diff', 'description' => 'The diff to review', 'required' => true],
            ['name' => 'focus'], // simple form: no required flag = optional per MCP spec
        ]);
        Assert::true(isset($byName['plain-note']));
    }

    public function getSubstitutesDeclaredArgumentsOnly(): void
    {
        $result = $this->tester()->request('prompts/get', [
            'name' => 'code-review',
            'arguments' => ['diff' => '- old\n+ new', 'focus' => 'security'],
        ]);

        $message = ((array) ($result['messages'] ?? []))[0] ?? [];

        Assert::same($message['role'] ?? null, 'user');
        Assert::string($this->messageText($result))
            ->contains('focusing on security')
            ->contains('- old\n+ new')
            ->contains('{{undeclared}}');
    }

    public function missingArgumentBecomesEmptyString(): void
    {
        $result = $this->tester()->request('prompts/get', [
            'name' => 'code-review',
            'arguments' => ['diff' => 'D'],
        ]);

        Assert::string($this->messageText($result))->contains('focusing on :');
    }

    public function nameDefaultsToFileNameAndBodyIsServedAsIs(): void
    {
        $result = $this->tester()->request('prompts/get', ['name' => 'plain-note']);

        Assert::same(
            $this->messageText($result),
            "A prompt without frontmatter: its name comes from the file name.\n",
        );
    }

    public function missingDirectoryThrows(): void
    {
        Expect::exception(InvalidPromptFileException::class);

        $this->server(__DIR__ . '/nonexistent');
    }

    public function malformedFrontmatterThrows(): void
    {
        Expect::exception(InvalidPromptFileException::class);

        $this->server(\dirname(__DIR__) . '/Support/prompts-invalid');
    }

    public function duplicatePromptNameThrows(): void
    {
        $caught = null;

        try {
            $this->server(\dirname(__DIR__) . '/Support/prompts-duplicate');
        } catch (InvalidPromptFileException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('Duplicate prompt name "dup"');
    }

    /**
     * @param array<array-key, mixed> $result
     */
    private function messageText(array $result): string
    {
        $message = ((array) ($result['messages'] ?? []))[0] ?? [];
        $content = is_array($message) ? ((array) ($message['content'] ?? [])) : [];
        $text = $content['text'] ?? '';

        return is_string($text) ? $text : '';
    }

    private function tester(): McpTester
    {
        $factory = new Psr17Factory();

        return new McpTester(
            server: $this->server(\dirname(__DIR__) . '/Support/prompts'),
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    private function server(string $promptsPath): Server
    {
        return (new McpServerFactory(
            container: new SimpleContainer([]),
            sessionStore: new InMemorySessionStore(),
            name: 'prompts-suite',
            version: '1.0.0',
        ))->create([], [new MarkdownPromptsConfigurator($promptsPath)]);
    }
}
