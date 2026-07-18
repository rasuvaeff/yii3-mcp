<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Doctor;

/**
 * Immutable result of one {@see McpDoctor} check. Details are human-readable
 * and never contain secrets or header values.
 *
 * @api
 */
final readonly class CheckResult
{
    public function __construct(
        public string $name,
        public CheckCategory $category,
        public CheckStatus $status,
        public string $details,
    ) {}

    /**
     * @return array{name: string, category: string, status: string, details: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category->value,
            'status' => $this->status->value,
            'details' => $this->details,
        ];
    }
}
