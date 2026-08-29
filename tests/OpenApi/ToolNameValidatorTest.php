<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\OpenApi;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Mcp\OpenApi\ToolNameValidator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ToolNameValidator::class)]
final class ToolNameValidatorTest
{
    /**
     * The full accepted charset, spelled out because `strspn()` takes a
     * character list and not ranges. The model must not be narrower than the
     * class under test — a sampled alphabet would call a perfectly valid `c`
     * invalid the moment a generator produced one.
     */
    private const string ACCEPTED = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._/-';

    /**
     * A narrower alphabet to generate from: one representative per accepted
     * class keeps the shrunk counterexample readable.
     */
    private const string ACCEPTED_SAMPLE = 'abzABZ09._/-';

    /**
     * Characters that must be rejected — `\n` first among them, since PCRE's
     * `$` matches before a trailing newline and would accept `"tool\n"` if
     * the pattern ever lost its `\z` anchor.
     */
    private const string REJECTED = "\n\r\t %:#?*+\\|<>\"'()[]{}&=,;!@$^~`üЖ";

    /**
     * Stated as charset + length rather than by re-running the class's own
     * pattern: a property that re-implements the regex it tests proves only
     * that PCRE is deterministic.
     */
    #[Property(runs: 500, timeoutMs: 250)]
    public function acceptsExactlyTheAllowedCharsetWithinTheLengthLimit(string $name): void
    {
        $length = strlen($name);
        $wellFormed = $length >= 1 && $length <= 64 && strspn($name, self::ACCEPTED) === $length;

        Classify::cover($wellFormed, 'valid name', 15.0);
        Classify::cover(!$wellFormed, 'rejected name', 15.0);
        Classify::when($length > 64, 'over the length limit');
        Classify::when($length >= 1 && strspn($name, self::ACCEPTED) !== $length, 'out-of-charset character');

        Assert::same(ToolNameValidator::isValid($name), $wellFormed);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function acceptsExactlyTheAllowedCharsetWithinTheLengthLimitGenerators(): array
    {
        return [
            'name' => Gen::frequency([
                [3, Gen::stringFrom(self::ACCEPTED_SAMPLE, 0, 70)],
                [2, Gen::stringFrom(self::ACCEPTED_SAMPLE . self::REJECTED, 0, 70)],
                [1, Gen::stringAscii()],
            ]),
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptsExactlyTheAllowedCharsetWithinTheLengthLimitExamples(): iterable
    {
        yield 'empty' => [''];
        yield 'single character' => ['a'];
        yield 'every accepted class' => ['aZ0._/-'];
        yield 'exactly 64 characters' => [str_repeat('a', 64)];
        yield 'one over the limit' => [str_repeat('a', 65)];
        // the `\z`-vs-`$` regression: PCRE's `$` matches before a trailing
        // newline, so this name is accepted by an unanchored pattern and a
        // control character reaches tools/list
        yield 'trailing newline' => ["weather\n"];
        yield 'leading newline' => ["\nweather"];
        yield 'embedded newline' => ["weather\nforecast"];
        yield 'space' => ['get weather'];
        yield 'colon' => ['tag:admin'];
        yield 'non-ascii' => ['погода'];
        yield 'null byte' => ["weather\0"];
    }
}
