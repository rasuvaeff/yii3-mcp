<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Prompts\Exception;

use RuntimeException;

/**
 * A prompt Markdown file (or the prompts directory) is unusable: unreadable,
 * malformed frontmatter, empty name or a duplicate name. Thrown at server
 * build time — fail-fast, never a silently missing prompt.
 *
 * @api
 */
final class InvalidPromptFileException extends RuntimeException {}
