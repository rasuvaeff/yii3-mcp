<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Doctor;

/**
 * Outcome of a single {@see McpDoctor} check.
 *
 * @api
 */
enum CheckStatus: string
{
    case Pass = 'pass';
    case Skip = 'skip';
    case Fail = 'fail';
}
