<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

/**
 * Shared between SpecIndex (raw operationId) and OpenApiServerConfigurator
 * (operationId renamed via `tool_names`): mcp/sdk's own
 * Capability\Tool\NameValidator only logs a warning on a mismatch and still
 * registers the tool, so both call sites fail closed instead of relying on
 * the SDK. Charset matches the SDK validator; `\z`, not `$` — PCRE `$`
 * matches before a trailing "\n".
 *
 * @internal
 */
final readonly class ToolNameValidator
{
    private const string PATTERN = '/^[A-Za-z0-9._\/-]{1,64}\z/';

    public static function isValid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }
}
