<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

/**
 * Byte-budget truncation that never leaves a half-written UTF-8 character
 * behind. Every string this package cuts down (an over-limit tool result, an
 * upstream error-body excerpt) ends up inside a JSON-RPC response the SDK
 * encodes with JSON_THROW_ON_ERROR — and a broken multi-byte sequence makes
 * that encode fail, which on the Streamable HTTP transport drops the whole
 * response silently.
 *
 * Implemented byte-wise on purpose: mb_strcut would pull ext-mbstring into
 * the package requirements (and into every CI job's extension list) for two
 * call sites.
 *
 * @internal
 */
final readonly class Utf8
{
    /**
     * Truncates to at most $maxBytes bytes, dropping a trailing character
     * only when it did not fit whole. A cut that already lands on a
     * character boundary keeps everything up to it.
     */
    public static function cut(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $cut = substr($value, 0, $maxBytes);
        $index = strlen($cut) - 1;

        // walk back over continuation bytes (10xxxxxx) to the lead byte
        while ($index >= 0 && (ord($cut[$index]) & 0xC0) === 0x80) {
            --$index;
        }

        if ($index < 0) {
            // no lead byte at all — the input was not valid UTF-8 to begin with
            return '';
        }

        $lead = ord($cut[$index]);
        // the lead byte declares how many bytes the character occupies;
        // guessing from the trailing bytes alone would drop a character that
        // did fit whole
        $needed = match (true) {
            $lead >= 0xF0 => 4,
            $lead >= 0xE0 => 3,
            $lead >= 0xC0 => 2,
            default => 1,
        };

        return strlen($cut) - $index >= $needed ? $cut : substr($cut, 0, $index);
    }
}
