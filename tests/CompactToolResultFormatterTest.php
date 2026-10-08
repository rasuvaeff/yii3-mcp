<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Mcp\CompactToolResultFormatter;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CompactToolResultFormatter::class)]
final class CompactToolResultFormatterTest
{
    public function arrayResultBecomesSingleCompactTextWithStructuredContent(): void
    {
        $result = $this->format(['items' => ['hammer', 'nails'], 'total' => 2]);

        Assert::same($this->texts($result->content), ['{"items":["hammer","nails"],"total":2}']);
        Assert::same($result->structuredContent, ['items' => ['hammer', 'nails'], 'total' => 2]);
        Assert::false($result->isError);
    }

    public function unicodeAndSlashesAreNotEscaped(): void
    {
        $result = $this->format(['город' => 'Санкт-Петербург/Москва']);

        Assert::same($result->content[0]->text, '{"город":"Санкт-Петербург/Москва"}');
    }

    /**
     * `[]` is a PHP list: no structuredContent before 2026-07-28 (only a JSON
     * object is valid there), an empty list from 2026-07-28 on.
     */
    public function emptyArrayResultKeepsTheSdkShape(): void
    {
        $handshake = $this->format([]);
        $modern = $this->format([], ProtocolVersion::V2026_07_28);

        Assert::same($this->texts($handshake->content), ['[]']);
        Assert::null($handshake->structuredContent);
        Assert::same($modern->structuredContent, []);
    }

    public function nullResultKeepsTheSdkSentinel(): void
    {
        $result = $this->format(null);

        Assert::same($this->texts($result->content), ['(null)']);
        Assert::null($result->structuredContent);
    }

    public function boolResultsKeepTheSdkSentinels(): void
    {
        Assert::same($this->texts($this->format(result: true)->content), ['true']);
        Assert::same($this->texts($this->format(result: false)->content), ['false']);
    }

    public function scalarResultsPassThroughAsStringContent(): void
    {
        Assert::same($this->texts($this->format(42)->content), ['42']);
        Assert::same($this->texts($this->format(1.5)->content), ['1.5']);
        Assert::same($this->texts($this->format('plain')->content), ['plain']);
        Assert::null($this->format('plain')->structuredContent);
    }

    public function contentInstanceIsWrappedWithoutStructuredContent(): void
    {
        $content = new TextContent('already formatted');

        $result = $this->format($content);

        Assert::same($result->content, [$content]);
        Assert::null($result->structuredContent);
    }

    public function allContentArrayIsPassedThrough(): void
    {
        $first = new TextContent('one');
        $second = new TextContent('two');

        $result = $this->format([$first, $second]);

        Assert::same($result->content, [$first, $second]);
    }

    public function mixedArrayFormatsOnlyTheNonContentItems(): void
    {
        $content = new TextContent('kept');

        $result = $this->format([$content, 2, ['k' => 'v']]);

        Assert::same($this->texts($result->content), ['kept', '2', '{"k":"v"}']);
        // the Content instance itself is passed through untouched, not re-created
        Assert::same($result->content[0], $content);
    }

    /**
     * structuredContent is the SDK's own extraction for the given revision —
     * the formatter only adds the compact text.
     *
     * @param array<string, mixed>|null $outputSchema
     */
    private function format(
        mixed $result,
        ProtocolVersion $version = ProtocolVersion::V2025_11_25,
        ?array $outputSchema = null,
    ): CallToolResult {
        return CompactToolResultFormatter::format($result, $this->reference($outputSchema), $version);
    }

    /**
     * @param array<string, mixed>|null $outputSchema
     */
    private function reference(?array $outputSchema = null): ToolReference
    {
        return new ToolReference(
            new Tool(
                name: 'probe',
                title: null,
                inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
                description: null,
                annotations: null,
                outputSchema: $outputSchema,
            ),
            static fn(): null => null,
        );
    }

    /**
     * Before 2026-07-28 structuredContent must be a JSON object, so a list
     * result carries no structuredContent (strict clients reject the whole
     * call otherwise); from 2026-07-28 on (SEP-2106) the list is sent.
     */
    public function listResultIsStructuredOnlyFromTheModernRevision(): void
    {
        $handshake = $this->format(['a', 'b'], ProtocolVersion::V2025_11_25);
        $modern = $this->format(['a', 'b'], ProtocolVersion::V2026_07_28);

        Assert::same($this->texts($handshake->content), ['["a","b"]']);
        Assert::null($handshake->structuredContent);
        Assert::same($this->texts($modern->content), ['["a","b"]']);
        Assert::same($modern->structuredContent, ['a', 'b']);
    }

    /**
     * The formatter must agree with the SDK's pretty path on structuredContent
     * for every revision — a difference here is drift the next SDK bump would
     * hide (the 0.7 formatter mirrored rules the 0.8 SDK changed).
     */
    #[DataProvider('structuredContentAgreesWithTheSdkProvider')]
    public function structuredContentAgreesWithTheSdk(mixed $result, ProtocolVersion $version, ?array $outputSchema): void
    {
        $reference = $this->reference($outputSchema);

        Assert::same(
            CompactToolResultFormatter::format($result, $reference, $version)->structuredContent,
            $reference->extractStructuredContent($result, $version),
        );
    }

    public static function structuredContentAgreesWithTheSdkProvider(): iterable
    {
        foreach (ProtocolVersion::cases() as $version) {
            yield $version->value . ' object' => [['k' => 'v'], $version, null];
            yield $version->value . ' list' => [[1, 2], $version, null];
            yield $version->value . ' empty' => [[], $version, null];
            yield $version->value . ' scalar with schema' => [42, $version, ['type' => 'integer']];
            yield $version->value . ' scalar without schema' => ['plain', $version, null];
            yield $version->value . ' content inside' => [[new TextContent('x'), 1], $version, null];
        }
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
        $result = $this->format((object) ['a' => 1, 'nested' => (object) ['b' => true]]);

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
        $within = $this->format($this->nestedObject(511));

        Assert::same($within->structuredContent, $this->nestedArray(511));

        Expect::exception(\JsonException::class);

        $this->format($this->nestedObject(512));
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
     *
     * Stated under 2026-07-28, the revision where every array result is
     * structured content; before it a list result carries none (see
     * listResultIsStructuredOnlyFromTheModernRevision).
     */
    #[Property(runs: 200)]
    public function compactTextIsTheEncodingOfTheStructuredContent(array $result): void
    {
        $formatted = $this->format($result, ProtocolVersion::V2026_07_28);

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
