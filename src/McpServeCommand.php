<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\TransportInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Serves the MCP server over the stdio transport — for local development
 * with Claude Code / Claude Desktop and other stdio-based MCP clients.
 *
 * @api
 */
#[AsCommand(name: 'mcp:serve', description: 'Run the MCP server on the stdio transport')]
final class McpServeCommand extends Command
{
    /**
     * @param TransportInterface<mixed>|null $transport replaces the stdio transport — a seam for tests
     */
    public function __construct(
        private readonly Server $server,
        private readonly ?TransportInterface $transport = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->server->run($this->transport ?? new StdioTransport());

        return Command::SUCCESS;
    }
}
