<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Mcp\Server;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Testing\SchemaSnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints every registered MCP capability (tools, resources, resource
 * templates, prompts) without an MCP client: the introspection goes through
 * the same in-process JSON-RPC path a real client uses, so the output shows
 * what is actually served — attribute tools, OpenAPI-bridged operations and
 * Markdown prompts alike.
 *
 * `--json` prints the full capability definitions as normalized JSON (the
 * SchemaSnapshot format: sections keyed, items ordered by identity, object
 * keys sorted) — stable for CI diffs and external automation.
 *
 * @api
 */
#[AsCommand(name: 'mcp:list', description: 'List registered MCP tools, resources and prompts')]
final class McpListCommand extends Command
{
    public function __construct(
        private readonly Server $server,
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(name: 'json', mode: InputOption::VALUE_NONE, description: 'Output full capability definitions as normalized JSON');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tester = new McpTester($this->server, $this->requestFactory, $this->responseFactory, $this->streamFactory);

        if ($input->getOption('json') === true) {
            $json = json_encode(
                SchemaSnapshot::capture($tester),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $output->writeln($json, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);

        $this->section($io, 'Tools', $tester->listTools(), ['Name', 'Description', 'Arguments'], $this->toolRow(...));
        $this->section($io, 'Resources', $tester->listResources(), ['URI', 'Name', 'MIME type', 'Description'], $this->resourceRow(...));
        $this->section($io, 'Resource templates', $tester->listResourceTemplates(), ['URI template', 'Name', 'Description'], $this->templateRow(...));
        $this->section($io, 'Prompts', $tester->listPrompts(), ['Name', 'Description', 'Arguments'], $this->promptRow(...));

        return Command::SUCCESS;
    }

    /**
     * @param list<array<array-key, mixed>> $items
     * @param list<string> $headers
     * @param callable(array<array-key, mixed>): list<string> $row
     */
    private function section(SymfonyStyle $io, string $title, array $items, array $headers, callable $row): void
    {
        $io->section(sprintf('%s (%d)', $title, count($items)));

        if ($items === []) {
            $io->text('none');

            return;
        }

        $io->table($headers, array_map($row, $items));
    }

    /**
     * @param array<array-key, mixed> $tool
     *
     * @return list<string>
     */
    private function toolRow(array $tool): array
    {
        return [
            $this->str($tool['name'] ?? null),
            $this->str($tool['description'] ?? null),
            $this->arguments($tool['inputSchema'] ?? null),
        ];
    }

    /**
     * @param array<array-key, mixed> $resource
     *
     * @return list<string>
     */
    private function resourceRow(array $resource): array
    {
        return [
            $this->str($resource['uri'] ?? null),
            $this->str($resource['name'] ?? null),
            $this->str($resource['mimeType'] ?? null),
            $this->str($resource['description'] ?? null),
        ];
    }

    /**
     * @param array<array-key, mixed> $template
     *
     * @return list<string>
     */
    private function templateRow(array $template): array
    {
        return [
            $this->str($template['uriTemplate'] ?? null),
            $this->str($template['name'] ?? null),
            $this->str($template['description'] ?? null),
        ];
    }

    /**
     * @param array<array-key, mixed> $prompt
     *
     * @return list<string>
     */
    private function promptRow(array $prompt): array
    {
        $arguments = [];
        /** @var mixed $argument */
        foreach (is_array($prompt['arguments'] ?? null) ? $prompt['arguments'] : [] as $argument) {
            if (!is_array($argument)) {
                continue;
            }

            /** @var mixed $name */
            $name = $argument['name'] ?? null;

            if (is_string($name)) {
                $arguments[] = $name . (($argument['required'] ?? false) === true ? '*' : '');
            }
        }

        return [
            $this->str($prompt['name'] ?? null),
            $this->str($prompt['description'] ?? null),
            implode(', ', $arguments),
        ];
    }

    /**
     * Renders a JSON-Schema object as "name*, other" — required arguments
     * are marked with an asterisk.
     */
    private function arguments(mixed $schema): string
    {
        if (!is_array($schema)) {
            return '';
        }

        $names = [];
        /** @var mixed $name */
        foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        $required = array_flip($names);
        $arguments = [];
        /** @var mixed $properties */
        $properties = $schema['properties'] ?? null;

        foreach (is_array($properties) ? array_keys($properties) : [] as $name) {
            $arguments[] = $name . (array_key_exists($name, $required) ? '*' : '');
        }

        return implode(', ', $arguments);
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
