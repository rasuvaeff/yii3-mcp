<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Testing;

/**
 * Extracts the JSON-RPC payload from a Streamable HTTP response body:
 * a plain JSON body is returned as-is; an SSE-framed body yields the data
 * of its first event, with multi-line `data:` fields joined by newlines
 * per the SSE specification.
 *
 * @internal
 */
final readonly class SseFrame
{
    public static function payload(string $raw): string
    {
        $trimmed = trim($raw);

        if (!str_starts_with($trimmed, 'event:') && !str_starts_with($trimmed, 'data:')) {
            return $raw;
        }

        $firstEvent = explode("\n\n", str_replace(["\r\n", "\r"], "\n", $trimmed))[0];
        preg_match_all('/^data: ?(.*)$/m', $firstEvent, $matches);

        return implode("\n", $matches[1]);
    }
}
