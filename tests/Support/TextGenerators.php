<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * Shared string generators for the property tests. A provider class rather
 * than private helpers inside a test class: rector's
 * `LocallyCalledStaticMethodToNonStaticRector` rewrites a `private static`
 * helper into an instance method the moment a property body calls it, which
 * breaks the `public static <method>Generators()` contract on the next
 * release-check.
 */
final readonly class TextGenerators
{
    /**
     * Deliberately mixes character widths: `Gen::string()` alone is dominated
     * by four-byte codepoints, and the interesting boundaries in
     * {@see \Rasuvaeff\Yii3Mcp\Utf8::cut()} are the 2- and 3-byte lead bytes.
     */
    public static function mixedWidthUtf8(): ArbitraryInterface
    {
        return Gen::frequency([
            [2, Gen::stringFrom('ab.:_ é±中文👍🙂', 0, 24)],
            [1, Gen::stringAscii()],
            [1, Gen::stringOf(0, 24)],
        ]);
    }
}
