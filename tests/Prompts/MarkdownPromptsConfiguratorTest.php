<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Prompts;

use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\PromptArgument;
use Mcp\Server;
use Mcp\Server\ClientGateway;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Prompts\Exception\InvalidPromptFileException;
use Rasuvaeff\Yii3Mcp\Prompts\FilePromptHandler;
use Rasuvaeff\Yii3Mcp\Prompts\MarkdownPromptsConfigurator;
use Rasuvaeff\Yii3Mcp\Prompts\PromptFile;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeSession;
use Rasuvaeff\Yii3Mcp\Tests\Support\ThrowingStreamWrapper;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(MarkdownPromptsConfigurator::class)]
#[Covers(InvalidPromptFileException::class)]
#[Covers(FilePromptHandler::class)]
#[Covers(PromptFile::class)]
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

    public function malformedArgumentEntryThrows(): void
    {
        $caught = null;

        try {
            $this->server(\dirname(__DIR__) . '/Support/prompts-malformed-argument');
        } catch (InvalidPromptFileException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('malformed argument entry');
    }

    public function argumentEntryWithoutANameThrows(): void
    {
        $caught = null;

        try {
            $this->server(\dirname(__DIR__) . '/Support/prompts-nameless-argument');
        } catch (InvalidPromptFileException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('argument without a name');
    }

    public function emptyDirectoryRegistersNoPrompts(): void
    {
        $factory = new Psr17Factory();
        $tester = new McpTester(
            server: $this->server(\dirname(__DIR__) . '/Support/prompts-empty'),
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );

        Assert::same($tester->request('prompts/list')['prompts'] ?? null, []);
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

    public function unreadableFileThrowsWithTheFileName(): void
    {
        // a broken symlink: file_get_contents() returns false (not a
        // directory, which reads as "" on Linux, not false)
        $dir = sys_get_temp_dir() . '/yii3-mcp-prompt-' . bin2hex(random_bytes(8));
        mkdir($dir);
        $link = $dir . '/broken.md';
        symlink($dir . '/does-not-exist', $link);

        try {
            $caught = null;

            try {
                PromptFile::parse($link);
            } catch (InvalidPromptFileException $caught) {
            }

            Assert::notNull($caught);
            Assert::string($caught->getMessage())
                ->contains($link)
                ->contains('is not readable');
        } finally {
            unlink($link);
            rmdir($dir);
        }
    }

    public function parseSuppressesTheUnderlyingReadWarningEntirely(): void
    {
        $dir = sys_get_temp_dir() . '/yii3-mcp-prompt-' . bin2hex(random_bytes(8));
        mkdir($dir);
        $link = $dir . '/broken.md';
        symlink($dir . '/does-not-exist', $link);

        ob_start();

        try {
            $caught = null;

            try {
                PromptFile::parse($link);
            } catch (InvalidPromptFileException $caught) {
            }

            Assert::notNull($caught);
        } finally {
            $output = ob_get_clean();
            unlink($link);
            rmdir($dir);
        }

        // set_error_handler(static fn(): bool => true) must fully absorb the
        // "failed to open stream" warning (returning `true` tells PHP the
        // error is handled, skipping its normal — printing — handler); a
        // leaked warning would corrupt whatever output the caller is
        // building around this call (e.g. McpListCommand's --json mode)
        Assert::same($output, '');
    }

    public function parseAlwaysRestoresTheErrorHandlerAfterwards(): void
    {
        PromptFile::parse(\dirname(__DIR__) . '/Support/prompts/plain-note.md');

        // if parse() failed to restore_error_handler(), this process would
        // still carry parse()'s own silencing handler; set_error_handler()
        // returns the PREVIOUSLY active one, null meaning "none" (default)
        $leaked = set_error_handler(static fn(): bool => true);
        restore_error_handler();

        Assert::null($leaked);
    }

    public function theErrorHandlerIsRestoredEvenWhenReadingThrows(): void
    {
        stream_wrapper_register('yii3mcp-test-throw', ThrowingStreamWrapper::class);

        try {
            $caught = null;

            try {
                PromptFile::parse('yii3mcp-test-throw://anything.md');
            } catch (RuntimeException $caught) {
            }

            Assert::notNull($caught);

            // the `finally` must run even though file_get_contents() threw
            // instead of returning false — without it, this process keeps
            // silencing every subsequent PHP warning forever
            $leaked = set_error_handler(static fn(): bool => true);
            restore_error_handler();

            Assert::null($leaked);
        } finally {
            stream_wrapper_unregister('yii3mcp-test-throw');
        }
    }

    public function embeddedFrontmatterDelimitersNotAtTheStartAreIgnored(): void
    {
        // the `---` block starts mid-file, not at position 0: without the
        // leading `^` anchor, preg_match would find it anyway and wrongly
        // treat "embedded" as the frontmatter-declared name
        $path = $this->writeTempPromptFile(
            "Some intro text\n---\nname: embedded\n---\nBody\n",
        );

        try {
            $file = PromptFile::parse($path);

            // no `^`-anchored match at all: the whole raw text is the body,
            // and the name falls back to the file name, not "embedded"
            Assert::same($file->name, basename($path, '.md'));
            Assert::same($file->content, "Some intro text\n---\nname: embedded\n---\nBody\n");
        } finally {
            unlink($path);
        }
    }

    public function aSimpleStringArgumentDoesNotStopLaterArgumentsFromBeingParsed(): void
    {
        $path = $this->writeTempPromptFile(<<<'MD'
            ---
            arguments:
              - first
              - name: second
                description: Second arg
            ---
            Body
            MD);

        try {
            $file = PromptFile::parse($path);

            Assert::same(array_map(static fn(PromptArgument $argument): string => $argument->name, $file->arguments), ['first', 'second']);
        } finally {
            unlink($path);
        }
    }

    public function anArgumentWithoutAnExplicitRequiredKeyDefaultsToFalse(): void
    {
        $path = $this->writeTempPromptFile(<<<'MD'
            ---
            arguments:
              - name: optional
            ---
            Body
            MD);

        try {
            $file = PromptFile::parse($path);

            Assert::same($file->arguments[0]->required, false);
        } finally {
            unlink($path);
        }
    }

    private function writeTempPromptFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/yii3-mcp-prompt-' . bin2hex(random_bytes(8)) . '.md';
        file_put_contents($path, $content);

        return $path;
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

    public function expansionOverTheBudgetFailsInsteadOfAllocating(): void
    {
        // ten occurrences of {{payload}} amplify the caller's bytes tenfold;
        // the budget is checked arithmetically BEFORE the substituted string
        // is built. The SDK reports handler failures opaquely, so the precise
        // message is asserted at the handler level below.
        $tester = $this->amplifyTester(maxResultBytes: 200);
        $caught = null;

        try {
            $tester->request('prompts/get', [
                'name' => 'amplify',
                'arguments' => ['payload' => str_repeat('A', 50)],
            ]);
        } catch (\RuntimeException $caught) {
        }

        Assert::notNull($caught);
    }

    public function expansionBudgetIsComputedFromOccurrenceCountsNotBuilt(): void
    {
        $template = str_repeat('{{payload}} ', 10);
        $handler = new FilePromptHandler(
            content: $template,
            argumentNames: ['payload'],
            promptName: 'amplify',
            maxResultBytes: 200,
        );

        $caught = null;

        try {
            $handler->get(['payload' => str_repeat('A', 50)], new ClientGateway(new FakeSession()));
        } catch (\RuntimeException $caught) {
        }

        Assert::notNull($caught);
        // 10 × 50 bytes + separators — the predicted size is exact
        Assert::string($caught->getMessage())
            ->contains('amplify')
            ->contains('would expand to 510 bytes, over the 200-byte limit');
    }

    public function expansionExactlyAtTheBudgetIsServed(): void
    {
        // one placeholder occurrence of length 11 replaced by a 5-byte value:
        // the substituted size is exactly 5 bytes, matching maxResultBytes —
        // the boundary must be accepted (">", not ">=")
        $handler = new FilePromptHandler(
            content: '{{payload}}',
            argumentNames: ['payload'],
            promptName: 'exact',
            maxResultBytes: 5,
        );

        /** @var PromptMessage $message */
        $message = $handler->get(['payload' => 'ABCDE'], new ClientGateway(new FakeSession()));

        Assert::same($message->content->text, 'ABCDE');
    }

    public function nonStringScalarArgumentValueIsCastToString(): void
    {
        $handler = new FilePromptHandler(content: 'n={{n}}', argumentNames: ['n']);

        /** @var PromptMessage $message */
        $message = $handler->get(['n' => 42], new ClientGateway(new FakeSession()));

        Assert::same($message->content->text, 'n=42');
    }

    public function expansionWithinTheBudgetIsServed(): void
    {
        $tester = $this->amplifyTester(maxResultBytes: 200);

        $result = $tester->request('prompts/get', [
            'name' => 'amplify',
            'arguments' => ['payload' => 'ok'],
        ]);

        Assert::string($this->messageText($result))->contains('ok ok');
    }

    public function zeroBudgetDisablesTheExpansionLimit(): void
    {
        $tester = $this->amplifyTester(maxResultBytes: 0);

        $result = $tester->request('prompts/get', [
            'name' => 'amplify',
            'arguments' => ['payload' => str_repeat('A', 50)],
        ]);

        Assert::string($this->messageText($result))->contains(str_repeat('A', 50));
    }

    private function amplifyTester(int $maxResultBytes): McpTester
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([]),
            sessionStore: new InMemorySessionStore(),
            name: 'prompts-suite',
            version: '1.0.0',
        ))->create([], [new MarkdownPromptsConfigurator(
            \dirname(__DIR__) . '/Support/prompts-amplify',
            maxResultBytes: $maxResultBytes,
        )]);

        return new McpTester(server: $server, requestFactory: $factory, responseFactory: $factory, streamFactory: $factory);
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
