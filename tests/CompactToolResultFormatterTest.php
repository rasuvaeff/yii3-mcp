<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Schema\Content\TextContent;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Mcp\CompactToolResultFormatter;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CompactToolResultFormatter::class)]
final class CompactToolResultFormatterTest
{
    public function arrayResultBecomesSingleCompactTextWithStructuredContent(): void
    {
        $result = CompactToolResultFormatter::format(['items' => ['hammer', 'nails'], 'total' => 2]);

        Assert::same($this->texts($result->content), ['{"items":["hammer","nails"],"total":2}']);
        Assert::same($result->structuredContent, ['items' => ['hammer', 'nails'], 'total' => 2]);
        Assert::false($result->isError);
    }

    public function unicodeAndSlashesAreNotEscaped(): void
    {
        $result = CompactToolResultFormatter::format(['город' => 'Санкт-Петербург/Москва']);

        Assert::same($result->content[0]->text, '{"город":"Санкт-Петербург/Москва"}');
    }

    public function emptyArrayResultKeepsTheSdkShape(): void
    {
        $result = CompactToolResultFormatter::format([]);

        Assert::same($this->texts($result->content), ['[]']);
        Assert::same($result->structuredContent, []);
    }

    public function nullResultKeepsTheSdkSentinel(): void
    {
        $result = CompactToolResultFormatter::format(null);

        Assert::same($this->texts($result->content), ['(null)']);
        Assert::null($result->structuredContent);
    }

    public function boolResultsKeepTheSdkSentinels(): void
    {
        Assert::same($this->texts(CompactToolResultFormatter::format(toolExecutionResult: true)->content), ['true']);
        Assert::same($this->texts(CompactToolResultFormatter::format(toolExecutionResult: false)->content), ['false']);
    }

    public function scalarResultsPassThroughAsStringContent(): void
    {
        Assert::same($this->texts(CompactToolResultFormatter::format(42)->content), ['42']);
        Assert::same($this->texts(CompactToolResultFormatter::format(1.5)->content), ['1.5']);
        Assert::same($this->texts(CompactToolResultFormatter::format('plain')->content), ['plain']);
        Assert::null(CompactToolResultFormatter::format('plain')->structuredContent);
    }

    public function contentInstanceIsWrappedWithoutStructuredContent(): void
    {
        $content = new TextContent('already formatted');

        $result = CompactToolResultFormatter::format($content);

        Assert::same($result->content, [$content]);
        Assert::null($result->structuredContent);
    }

    public function allContentArrayIsPassedThrough(): void
    {
        $first = new TextContent('one');
        $second = new TextContent('two');

        $result = CompactToolResultFormatter::format([$first, $second]);

        Assert::same($result->content, [$first, $second]);
    }

    public function mixedArrayFormatsOnlyTheNonContentItems(): void
    {
        $content = new TextContent('kept');

        $result = CompactToolResultFormatter::format([$content, 2, ['k' => 'v']]);

        Assert::same($this->texts($result->content), ['kept', '2', '{"k":"v"}']);
        // the Content instance itself is passed through untouched, not re-created
        Assert::same($result->content[0], $content);
    }

    /**
     * TextContent is a class, so `===` on two instances is identity, not
     * equality — compare the carried text instead.
     *
     * @param array<int, TextContent> $content
     *
     * @return list<string>
     */
    private function texts(array $content): array
    {
        return array_map(static fn(TextContent $item): string => (string) $item->text, $content);
    }

    public function objectResultBecomesCompactJsonWithRoundTripStructuredContent(): void
    {
        $result = CompactToolResultFormatter::format((object) ['a' => 1, 'nested' => (object) ['b' => true]]);

        Assert::same($result->content[0]->text, '{"a":1,"nested":{"b":true}}');
        Assert::same($result->structuredContent, ['a' => 1, 'nested' => ['b' => true]]);
    }

    /**
     * The structured-content round trip uses the SDK's own depth budget of
     * 512 — which admits 511 nesting levels (the root level is counted too,
     * verified against json_decode directly): an object nested exactly that
     * deep decodes, one level deeper is a JsonException — not a silently
     * truncated payload.
     */
    public function structuredContentRoundTripStopsAtTheSdksDepthBudget(): void
    {
        $within = CompactToolResultFormatter::format($this->nestedObject(511));

        Assert::same($within->structuredContent, $this->nestedArray(511));

        Expect::exception(\JsonException::class);

        CompactToolResultFormatter::format($this->nestedObject(512))->structuredContent;
    }

    /**
     * Builds an object tree of the given nesting depth WITHOUT a bounded
     * json_decode — the default depth budget of 512 would throw inside the
     * test scaffolding for the very depths the assertion is about, making
     * the test vacuously green.
     */
    private function nestedObject(int $depth): \stdClass
    {
        $value = 'leaf';

        for ($i = 0; $i < $depth; ++$i) {
            $value = ['a' => $value];
        }

        /** @var \stdClass $object guarantee the object round-trip branch */
        $object = json_decode(json_encode($value, JSON_THROW_ON_ERROR), depth: 1024, flags: JSON_THROW_ON_ERROR);

        return $object;
    }

    /**
     * @return array<string, mixed>
     */
    private function nestedArray(int $depth): array
    {
        $value = 'leaf';

        for ($i = 0; $i < $depth; ++$i) {
            $value = ['a' => $value];
        }

        return $value;
    }

    /**
     * The compact text IS the JSON encoding of the structured payload — an
     * agent reading the text and the host reading structuredContent get the
     * same bytes of the same JSON document.
     *
     * Compared at the ENCODING level, not through a decode round trip: PHP
     * decodes JSON numbers without a decimal point as ints, so a float 0.0
     * does not survive json_decode(json_encode(0.0)) as a float — true for
     * the SDK's pretty-printed path alike, and JSON itself draws no int /
     * float distinction. The property must assert the encoding, not PHP's
     * decode-side typing.
     */
    #[Property(runs: 200)]
    public function compactTextIsTheEncodingOfTheStructuredContent(array $result): void
    {
        $formatted = CompactToolResultFormatter::format($result);

        /** @var string $text */
        $text = $formatted->content[0]->text;

        Assert::same(
            $text,
            json_encode($formatted->structuredContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface>
     */
    public static function compactTextIsTheEncodingOfTheStructuredContentGenerators(): array
    {
        $scalars = Gen::frequency([
            [1, Gen::int()],
            [1, Gen::bool()],
            [1, Gen::stringAscii()],
            [1, Gen::floatBetween(-1_000_000, 1_000_000)],
        ]);

        $values = Gen::frequency([
            [3, $scalars],
            [1, Gen::arrayOf($scalars)],
            [1, Gen::dictOf(Gen::stringAscii(), $scalars)],
        ]);

        return [
            'result' => Gen::frequency([
                [1, Gen::arrayOf($values)],
                [1, Gen::dictOf(Gen::stringAscii(), $values)],
            ]),
        ];
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function compactTextIsTheEncodingOfTheStructuredContentExamples(): iterable
    {
        yield 'empty array' => [[]];
        yield 'flat scalars' => [['a' => 1, 'b' => 'x', 'c' => null]];
        yield 'nested list and dict' => [['list' => [1, [2, 3]], 'dict' => ['k' => true]]];
        yield 'unicode and slashes' => [['город' => 'Санкт-Петербург/Москва']];
        yield 'integral float' => [['ratio' => 0.0]];
    }
}
