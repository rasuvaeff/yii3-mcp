<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Rasuvaeff\Yii3Mcp\Utf8;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Utf8::class)]
final class Utf8Test
{
    #[DataProvider('cutProvider')]
    public function cutsWithoutSplittingACharacter(string $value, int $maxBytes, string $expected): void
    {
        Assert::same(Utf8::cut($value, $maxBytes), $expected);
    }

    /**
     * The property that actually matters: whatever the cut position, the
     * result is encodable — a half-written character makes the SDK's
     * json_encode(..., JSON_THROW_ON_ERROR) fail on the whole response.
     */
    #[DataProvider('encodableProvider')]
    public function everyCutPositionStaysEncodable(string $value): void
    {
        for ($maxBytes = 0; $maxBytes <= strlen($value) + 1; $maxBytes++) {
            $cut = Utf8::cut($value, $maxBytes);

            Assert::true(self::isUtf8($cut), sprintf('invalid UTF-8 at maxBytes=%d', $maxBytes));
            Assert::true(strlen($cut) <= $maxBytes, sprintf('over the limit at maxBytes=%d', $maxBytes));
            json_encode($cut, JSON_THROW_ON_ERROR);
        }
    }

    /**
     * A cut landing exactly on a character boundary must keep everything up
     * to it: backing off one more character would be silent data loss that
     * no encoding assertion can see.
     */
    #[DataProvider('encodableProvider')]
    public function aBoundaryAlignedCutKeepsEverything(string $value): void
    {
        for ($maxBytes = 0; $maxBytes <= strlen($value); $maxBytes++) {
            if (!self::isUtf8(substr($value, 0, $maxBytes))) {
                continue;
            }

            Assert::same(Utf8::cut($value, $maxBytes), substr($value, 0, $maxBytes));
        }
    }

    public function inputWithoutALeadByteYieldsAnEmptyString(): void
    {
        // already-broken input: nothing but continuation bytes, so there is
        // no character to keep. cut() only avoids SPLITTING a character — it
        // does not repair input that was never valid UTF-8 (callers that
        // hand it foreign bytes validate separately, see
        // HttpOperationExecutor::errorExcerpt)
        Assert::same(Utf8::cut("\x80\x80\x80", 2), '');
    }

    public static function cutProvider(): iterable
    {
        yield 'shorter than the limit' => ['abc', 10, 'abc'];
        yield 'exactly at the limit' => ['abcde', 5, 'abcde'];
        yield 'ascii is cut byte-wise' => ['abcdefghij', 5, 'abcde'];
        yield 'zero limit' => ['abc', 0, ''];
        yield 'two-byte character does not fit' => ['привет', 5, 'пр'];
        yield 'two-byte character fits exactly' => ['привет', 6, 'при'];
        yield 'three-byte character does not fit' => ['中文测试', 5, '中'];
        yield 'three-byte character fits exactly' => ['中文测试', 6, '中文'];
        yield 'four-byte character does not fit' => ['👍👍', 5, '👍'];
        yield 'four-byte character fits exactly' => ['👍👍', 4, '👍'];
        yield 'ascii before a multi-byte tail' => ['a👍', 3, 'a'];
        // "अ" — a three-byte character whose lead byte is exactly 0xE0, the
        // boundary of the three-byte range
        yield 'three-byte lead at the range boundary needs all three' => ["\xE0\xA4\x85", 2, ''];
        // bytes that cannot start a character are dropped rather than kept:
        // cut() never returns a fragment it knows to be incomplete
        yield 'lead byte without its continuation' => ["\xC0\x80", 1, ''];
        // …but input within the limit is not touched at all: cut() avoids
        // splitting characters, it does not sanitize
        yield 'invalid input within the limit is returned verbatim' => ["\x80\x80", 2, "\x80\x80"];
    }

    /**
     * PCRE, not mb_check_encoding: ext-mbstring is not among the package's
     * requirements and not in the CI extension list, so a test using it would
     * pass locally in the composer image and fail on CI.
     */
    private static function isUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    public static function encodableProvider(): iterable
    {
        yield 'ascii' => ['abcdef'];
        yield 'two-byte' => ['привет мир'];
        yield 'three-byte' => ['中文测试'];
        yield 'four-byte' => ['👍👍👍'];
        yield 'mixed widths' => ['a👍б中c'];
    }
}
