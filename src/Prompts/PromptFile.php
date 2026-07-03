<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Prompts;

use Mcp\Schema\PromptArgument;
use Rasuvaeff\Yii3Mcp\Prompts\Exception\InvalidPromptFileException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One parsed prompt Markdown file: optional YAML frontmatter (`name`,
 * `title`, `description`, `arguments`) followed by the prompt body.
 * The file format is compatible with vjik/my-prompts-mcp, so prompts are
 * portable between a personal prompt manager and an application server.
 *
 * @internal
 */
final readonly class PromptFile
{
    /**
     * @param non-empty-string $name
     * @param list<PromptArgument> $arguments
     */
    private function __construct(
        public string $name,
        public ?string $title,
        public ?string $description,
        public array $arguments,
        public string $content,
    ) {}

    public static function parse(string $path): self
    {
        set_error_handler(static fn(): bool => true);
        $raw = file_get_contents($path);
        restore_error_handler();

        if ($raw === false) {
            throw new InvalidPromptFileException(sprintf('Prompt file "%s" is not readable', $path));
        }

        $meta = [];
        $body = $raw;

        if (preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $raw, $matches) === 1) {
            $meta = self::parseFrontmatter($matches[1], $path);
            $body = $matches[2];
        }

        $name = self::stringOrEmpty($meta['name'] ?? null);

        if ($name === '') {
            $name = basename($path, '.md');
        }

        if ($name === '') {
            throw new InvalidPromptFileException(sprintf('Prompt file "%s" has no usable name', $path));
        }

        $title = self::stringOrEmpty($meta['title'] ?? null);
        $description = self::stringOrEmpty($meta['description'] ?? null);

        return new self(
            name: $name,
            title: $title === '' ? null : $title,
            description: $description === '' ? null : $description,
            arguments: self::parseArguments($meta['arguments'] ?? null, $path),
            content: $body,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function parseFrontmatter(string $yaml, string $path): array
    {
        try {
            return self::arrayOrEmpty(Yaml::parse($yaml));
        } catch (ParseException $e) {
            throw new InvalidPromptFileException(sprintf('Prompt file "%s" has malformed YAML frontmatter: %s', $path, $e->getMessage()), $e->getCode(), previous: $e);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<PromptArgument>
     */
    private static function parseArguments(mixed $raw, string $path): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $arguments = [];
        /** @var mixed $item */
        foreach ($raw as $item) {
            // simple form: a plain string is the argument name
            if (is_string($item) && $item !== '') {
                $arguments[] = new PromptArgument(name: $item);

                continue;
            }

            if (is_array($item)) {
                $name = self::stringOrEmpty($item['name'] ?? null);

                if ($name === '') {
                    throw new InvalidPromptFileException(sprintf('Prompt file "%s" declares an argument without a name', $path));
                }

                $description = self::stringOrEmpty($item['description'] ?? null);
                $arguments[] = new PromptArgument(
                    name: $name,
                    description: $description === '' ? null : $description,
                    required: (bool) ($item['required'] ?? false),
                );

                continue;
            }

            throw new InvalidPromptFileException(sprintf('Prompt file "%s" declares a malformed argument entry', $path));
        }

        return $arguments;
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
