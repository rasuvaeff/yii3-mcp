<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpTool;

final readonly class StructuredWeatherTool
{
    /**
     * Current weather for a city.
     *
     * @return array{city: string, temperature: int, conditions: string}
     */
    #[McpTool(
        name: 'weather',
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'city' => ['type' => 'string'],
                'temperature' => ['type' => 'integer'],
                'conditions' => ['type' => 'string'],
            ],
            'required' => ['city', 'temperature', 'conditions'],
        ],
    )]
    public function weather(string $city): array
    {
        return ['city' => $city, 'temperature' => 21, 'conditions' => 'sunny'];
    }
}
