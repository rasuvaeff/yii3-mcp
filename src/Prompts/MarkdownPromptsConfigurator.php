<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Prompts;

use Mcp\Schema\Prompt;
use Mcp\Server\Builder;
use Rasuvaeff\Yii3Mcp\Prompts\Exception\InvalidPromptFileException;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;

/**
 * Registers every `*.md` file of a directory as an MCP prompt: YAML
 * frontmatter provides the metadata (`name` defaults to the file name),
 * the body is the prompt text with `{{argument}}` placeholders.
 *
 * Prompts become content, not code: edited without a deployment and
 * versioned like any other file.
 *
 * A missing directory throws at build time; a directory without `*.md`
 * files (or one the process cannot list) registers no prompts — an empty
 * prompts directory is a valid state, not an error.
 *
 * The file format is intentionally compatible with — and inspired by —
 * {@link https://github.com/vjik/my-prompts-mcp vjik/my-prompts-mcp}
 * by Sergei Predvoditelev.
 *
 * @api
 */
final readonly class MarkdownPromptsConfigurator implements ServerConfiguratorInterface
{
    public function __construct(
        private string $path,
    ) {}

    #[\Override]
    public function configure(Builder $builder): void
    {
        if (!is_dir($this->path)) {
            throw new InvalidPromptFileException(sprintf('Prompts directory "%s" does not exist', $this->path));
        }

        $seen = [];

        $files = glob($this->path . '/*.md');

        foreach ($files === false ? [] : $files as $file) {
            $prompt = PromptFile::parse($file);

            if (isset($seen[$prompt->name])) {
                throw new InvalidPromptFileException(sprintf(
                    'Duplicate prompt name "%s": declared by both "%s" and "%s"',
                    $prompt->name,
                    $seen[$prompt->name],
                    $file,
                ));
            }

            $seen[$prompt->name] = $file;

            $builder->add(
                definition: new Prompt(
                    name: $prompt->name,
                    title: $prompt->title,
                    description: $prompt->description,
                    arguments: $prompt->arguments === [] ? null : $prompt->arguments,
                ),
                handler: new FilePromptHandler(
                    content: $prompt->content,
                    argumentNames: array_map(
                        static fn($argument): string => $argument->name,
                        $prompt->arguments,
                    ),
                ),
            );
        }
    }
}
