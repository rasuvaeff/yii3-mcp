<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use RuntimeException;

final readonly class GreetingTool
{
    public function __construct(
        private string $prefix,
    ) {}

    /**
     * Greets the given person.
     */
    #[McpTool(name: 'greet')]
    public function greet(string $name): string
    {
        return $this->prefix . ', ' . $name . '!';
    }

    #[McpTool(name: 'explode')]
    public function explode(): string
    {
        throw new RuntimeException('sensitive internals');
    }

    #[McpResource(uri: 'app://status', name: 'status', mimeType: 'text/plain')]
    public function status(): string
    {
        return 'ok';
    }

    #[McpResourceTemplate(uriTemplate: 'app://users/{id}', name: 'user', mimeType: 'application/json')]
    public function user(string $id): string
    {
        return json_encode(['id' => $id], JSON_THROW_ON_ERROR);
    }

    /**
     * Politeness guidelines for greetings.
     *
     * @return array{user: string}
     */
    #[McpPrompt(name: 'greeting-style')]
    public function greetingStyle(): array
    {
        return ['user' => 'Greet the user warmly and by name.'];
    }
}
