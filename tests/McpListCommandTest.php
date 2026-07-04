<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\McpListCommand;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Prompts\MarkdownPromptsConfigurator;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(McpListCommand::class)]
final class McpListCommandTest
{
    public function succeedsAndPrintsEverySection(): void
    {
        $tester = $this->command($this->server());

        Assert::same($tester->execute([]), Command::SUCCESS);

        $display = $tester->getDisplay();
        Assert::string($display)->contains('Tools (2)');
        Assert::string($display)->contains('Resources (1)');
        Assert::string($display)->contains('Resource templates (1)');
        Assert::string($display)->contains('Prompts (3)');
    }

    public function printsFullHeaderRowForEverySection(): void
    {
        $tester = $this->command($this->server());
        $tester->execute([]);

        $display = $tester->getDisplay();
        Assert::same(preg_match_all('/Name\s+Description\s+Arguments/', $display), 2);
        Assert::same(preg_match_all('/URI\s+Name\s+MIME type\s+Description/', $display), 1);
        Assert::same(preg_match_all('/URI template\s+Name\s+Description/', $display), 1);
    }

    public function printsToolWithRequiredArgumentMarkedByAsterisk(): void
    {
        $tester = $this->command($this->server());
        $tester->execute([]);

        $display = $tester->getDisplay();
        Assert::same(preg_match('/greet\s+Greets the given person\.\s+name\*/', $display), 1);
    }

    public function printsResourceAndTemplateIdentifiers(): void
    {
        $tester = $this->command($this->server());
        $tester->execute([]);

        $display = $tester->getDisplay();
        Assert::string($display)->contains('app://status');
        Assert::string($display)->contains('text/plain');
        Assert::string($display)->contains('app://users/{id}');
    }

    public function printsPromptArgumentsWithRequiredMarker(): void
    {
        $tester = $this->command($this->server());
        $tester->execute([]);

        $display = $tester->getDisplay();
        Assert::string($display)->contains('code-review');
        Assert::string($display)->contains('diff*, focus');
        Assert::false(str_contains($display, 'focus*'));
    }

    public function printsNoneForEverySectionOfAnEmptyServer(): void
    {
        $tester = $this->command($this->emptyServer());

        Assert::same($tester->execute([]), Command::SUCCESS);

        $display = $tester->getDisplay();
        Assert::string($display)->contains('Tools (0)');
        Assert::string($display)->contains('Prompts (0)');
        Assert::same(substr_count($display, 'none'), 4);
        // no table is rendered for an empty section — not even a header row
        Assert::false(str_contains($display, 'Name'));
    }

    private function command(Server $server): CommandTester
    {
        $factory = new Psr17Factory();

        return new CommandTester(new McpListCommand(
            server: $server,
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        ));
    }

    private function server(): Server
    {
        return (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
            name: 'list-suite',
            version: '1.0.0',
        ))->create(
            [GreetingTool::class],
            [new MarkdownPromptsConfigurator(__DIR__ . '/Support/prompts')],
        );
    }

    private function emptyServer(): Server
    {
        return (new McpServerFactory(
            container: new SimpleContainer(),
            sessionStore: new InMemorySessionStore(),
            name: 'empty-suite',
            version: '1.0.0',
        ))->create([]);
    }
}
