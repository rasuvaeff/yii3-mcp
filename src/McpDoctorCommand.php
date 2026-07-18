<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Rasuvaeff\Yii3Mcp\Doctor\CheckResult;
use Rasuvaeff\Yii3Mcp\Doctor\CheckStatus;
use Rasuvaeff\Yii3Mcp\Doctor\McpDoctor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Health/readiness diagnostics for the MCP server configuration: endpoint
 * secret, session directory and store, OpenAPI spec, server build. Output
 * never contains the secret or configured header values.
 *
 * Exit codes are stable for scripting: 0 = healthy, 2 = config error,
 * 3 = storage error, 4 = upstream error (the category of the FIRST failing
 * check — checks run in diagnosis order, so the first failure is the root
 * cause). `--json` prints the machine-readable report; `--probe` allows
 * network access (fetching a URL OpenAPI spec) — without it the check stays
 * local and network-dependent checks are reported as skipped.
 *
 * @api
 */
#[AsCommand(name: 'mcp:doctor', description: 'Diagnose the MCP server configuration')]
final class McpDoctorCommand extends Command
{
    public function __construct(
        private readonly McpDoctor $doctor,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(name: 'json', mode: InputOption::VALUE_NONE, description: 'Output the machine-readable report as JSON');
        $this->addOption(name: 'probe', mode: InputOption::VALUE_NONE, description: 'Allow network access (fetch a URL OpenAPI spec, build the server that loads it)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->doctor->diagnose(probeUpstream: $input->getOption('probe') === true);

        if ($input->getOption('json') === true) {
            $output->writeln(
                json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                OutputInterface::OUTPUT_RAW,
            );

            return $report->exitCode();
        }

        $io = new SymfonyStyle($input, $output);
        $io->table(
            ['Status', 'Check', 'Category', 'Details'],
            array_map(
                static fn(CheckResult $check): array => [
                    match ($check->status) {
                        CheckStatus::Pass => '<info>pass</info>',
                        CheckStatus::Skip => '<comment>skip</comment>',
                        CheckStatus::Fail => '<error>FAIL</error>',
                    },
                    $check->name,
                    $check->category->value,
                    $check->details,
                ],
                $report->checks,
            ),
        );

        if ($report->healthy()) {
            $io->success('MCP server configuration is healthy');
        } else {
            $io->error(sprintf('MCP server configuration has problems (exit code %d)', $report->exitCode()));
        }

        return $report->exitCode();
    }
}
