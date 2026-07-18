<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Doctor;

/**
 * Failure domain of a {@see McpDoctor} check — determines the command's exit
 * code, so scripts can tell a misconfiguration from a broken disk from an
 * unreachable upstream without parsing output.
 *
 * @api
 */
enum CheckCategory: string
{
    case Config = 'config';
    case Storage = 'storage';
    case Upstream = 'upstream';

    /**
     * Stable per-category exit code (0 is reserved for a healthy report,
     * 1 for generic console errors).
     */
    public function exitCode(): int
    {
        return match ($this) {
            self::Config => 2,
            self::Storage => 3,
            self::Upstream => 4,
        };
    }
}
