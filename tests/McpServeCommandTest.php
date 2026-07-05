<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Rasuvaeff\Yii3Mcp\McpServeCommand;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\RecordingTransport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(McpServeCommand::class)]
final class McpServeCommandTest
{
    public function runsTheServerOverTheInjectedTransportAndSucceeds(): void
    {
        $transport = new RecordingTransport();
        $tester = new CommandTester(new McpServeCommand(
            server: $this->server(),
            transport: $transport,
        ));

        Assert::same($tester->execute([]), Command::SUCCESS);
        Assert::true($transport->listened);
    }

    public function registersUnderTheServeName(): void
    {
        $command = new McpServeCommand($this->server());

        Assert::same($command->getName(), 'mcp:serve');
        Assert::string($command->getDescription())->contains('stdio');
    }

    private function server(): Server
    {
        return (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
            name: 'serve-suite',
            version: '1.0.0',
        ))->create([GreetingTool::class]);
    }
}
