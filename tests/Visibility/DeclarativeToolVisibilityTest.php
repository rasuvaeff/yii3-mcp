<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Visibility;

use InvalidArgumentException;
use Mcp\Schema\Tool;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Mcp\Visibility\DeclarativeToolVisibility;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(DeclarativeToolVisibility::class)]
final class DeclarativeToolVisibilityTest
{
    public function allowsEverythingWithoutRules(): void
    {
        Assert::true((new DeclarativeToolVisibility())->isVisible($this->tool('anything'), null));
    }

    #[DataProvider('denyProvider')]
    public function denyPatternHidesMatchingTools(string $pattern, string $toolName, bool $visible): void
    {
        $visibility = new DeclarativeToolVisibility(deny: [$pattern]);

        Assert::same($visibility->isVisible($this->tool($toolName), null), $visible);
    }

    public static function denyProvider(): iterable
    {
        yield 'exact name' => ['admin.reset', 'admin.reset', false];
        yield 'prefix wildcard' => ['admin.*', 'admin.reset', false];
        yield 'suffix wildcard' => ['*.delete', 'order.delete', false];
        yield 'inner wildcard' => ['order.*.force', 'order.cancel.force', false];
        yield 'bare wildcard' => ['*', 'anything', false];
        yield 'other name stays visible' => ['admin.*', 'order.status', true];
        yield 'wildcard is not a dot-boundary' => ['admin*', 'administrate', false];
        yield 'no partial match without wildcard' => ['admin', 'admin.reset', true];
        yield 'dot is literal, not regex any-char' => ['admin.reset', 'adminXreset', true];
        yield 'trailing newline does not match exact name' => ['admin.reset', "admin.reset\n", true];
    }

    public function nonEmptyAllowListHidesEverythingElse(): void
    {
        $visibility = new DeclarativeToolVisibility(allow: ['order.*', 'greet']);

        Assert::true($visibility->isVisible($this->tool('order.status'), null));
        Assert::true($visibility->isVisible($this->tool('greet'), null));
        Assert::false($visibility->isVisible($this->tool('admin.reset'), null));
    }

    public function denyWinsOverAllow(): void
    {
        $visibility = new DeclarativeToolVisibility(deny: ['order.delete'], allow: ['order.*']);

        Assert::false($visibility->isVisible($this->tool('order.delete'), null));
        Assert::true($visibility->isVisible($this->tool('order.status'), null));
    }

    public function throwsOnEmptyPattern(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new DeclarativeToolVisibility(deny: ['']);
    }

    public function throwsOnEmptyTagPattern(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new DeclarativeToolVisibility(deny: ['tag:']);
    }

    public function tagDenyPatternHidesToolsCarryingTheTag(): void
    {
        $visibility = new DeclarativeToolVisibility(deny: ['tag:admin']);

        Assert::false($visibility->isVisible($this->toolWithTags('resetSystem', ['admin']), null));
        Assert::true($visibility->isVisible($this->toolWithTags('getStatus', ['public']), null));
    }

    public function tagDenyPatternSupportsWildcards(): void
    {
        $visibility = new DeclarativeToolVisibility(deny: ['tag:admin-*']);

        Assert::false($visibility->isVisible($this->toolWithTags('resetSystem', ['admin-write']), null));
        Assert::true($visibility->isVisible($this->toolWithTags('getStatus', ['admin']), null));
    }

    public function tagPatternNeverMatchesAToolWithoutTags(): void
    {
        $visibility = new DeclarativeToolVisibility(deny: ['tag:*']);

        Assert::true($visibility->isVisible($this->tool('plainTool'), null));
    }

    public function tagAllowPatternKeepsOnlyMatchingTools(): void
    {
        $visibility = new DeclarativeToolVisibility(allow: ['tag:public']);

        Assert::true($visibility->isVisible($this->toolWithTags('getStatus', ['public']), null));
        Assert::false($visibility->isVisible($this->toolWithTags('resetSystem', ['admin']), null));
        Assert::false($visibility->isVisible($this->tool('plainTool'), null));
    }

    public function namePatternDoesNotAccidentallyMatchAsATagPattern(): void
    {
        $visibility = new DeclarativeToolVisibility(deny: ['admin.*']);

        Assert::true($visibility->isVisible($this->toolWithTags('safeTool', ['admin.reset']), null));
    }

    public function tagPatternDoesNotAlsoActAsANamePattern(): void
    {
        $visibility = new DeclarativeToolVisibility(deny: ['tag:admin']);

        // a tool literally named "tag:admin" (untagged) must stay visible —
        // the tag: prefix is consumed once, not also compiled as a name pattern
        Assert::true($visibility->isVisible($this->tool('tag:admin'), null));
    }

    public function tagPatternDoesNotStopCompilingLaterPatterns(): void
    {
        $visibility = new DeclarativeToolVisibility(deny: ['tag:admin', 'order.delete']);

        Assert::false($visibility->isVisible($this->tool('order.delete'), null));
    }

    public function allTagsAreConsideredNotJustTheFirst(): void
    {
        $visibility = new DeclarativeToolVisibility(deny: ['tag:second']);

        Assert::false($visibility->isVisible($this->toolWithTags('op', ['first', 'second']), null));
    }

    public function nonArrayOwnMetaIsTreatedAsNoTags(): void
    {
        $tool = new Tool(
            name: 'x',
            title: null,
            inputSchema: ['type' => 'object'],
            description: null,
            annotations: null,
            meta: ['rasuvaeff/yii3-mcp' => 'not-an-array'],
        );

        Assert::true((new DeclarativeToolVisibility(deny: ['tag:*']))->isVisible($tool, null));
    }

    /**
     * The rule set is a small algebra, and the three laws below are what the
     * README promises. Deny beating allow is the one an operator's safety
     * depends on: a tool matched by any deny pattern must be invisible no
     * matter what the allow list says.
     *
     * @param list<string> $deny
     * @param list<string> $allow
     * @param list<string> $tags
     */
    #[Property(runs: 400, timeoutMs: 500)]
    public function denyAlwaysBeatsAllow(array $deny, array $allow, string $name, array $tags): void
    {
        $tool = $this->toolWithTags($name, $tags);
        $denied = (new DeclarativeToolVisibility(deny: $deny))->isVisible($tool, null) === false;

        Classify::cover($denied, 'matched a deny pattern', 15.0);
        Classify::cover(!$denied, 'not denied', 15.0);
        Classify::when($tags !== [], 'tool carries tags');

        if (!$denied) {
            return;
        }

        Assert::false((new DeclarativeToolVisibility(deny: $deny, allow: $allow))->isVisible($tool, null));
    }

    /**
     * With an empty allow list visibility is exactly "not denied" — the
     * documented default, and the branch that decides whether an unlisted
     * tool is served at all.
     *
     * @param list<string> $deny
     * @param list<string> $tags
     */
    #[Property(runs: 400, timeoutMs: 500)]
    public function anEmptyAllowListMeansEverythingNotDenied(array $deny, string $name, array $tags): void
    {
        $tool = $this->toolWithTags($name, $tags);
        $visibility = new DeclarativeToolVisibility(deny: $deny);

        $matchesDeny = false;

        foreach ($deny as $pattern) {
            if ((new DeclarativeToolVisibility(deny: [$pattern]))->isVisible($tool, null) === false) {
                $matchesDeny = true;
            }
        }

        Assert::same($visibility->isVisible($tool, null), !$matchesDeny);
    }

    /**
     * Adding a deny pattern can only ever remove visibility. A rule set that
     * gained a pattern and started SHOWING something is the failure mode an
     * operator would never think to look for.
     *
     * @param list<string> $deny
     * @param list<string> $allow
     * @param list<string> $tags
     */
    #[Property(runs: 400, timeoutMs: 500)]
    public function addingADenyPatternNeverRevealsATool(array $deny, array $allow, string $extra, string $name, array $tags): void
    {
        $tool = $this->toolWithTags($name, $tags);

        $before = (new DeclarativeToolVisibility(deny: $deny, allow: $allow))->isVisible($tool, null);
        $after = (new DeclarativeToolVisibility(deny: [...$deny, $extra], allow: $allow))->isVisible($tool, null);

        Classify::cover($before, 'visible before the extra deny', 20.0);
        Classify::when($before && !$after, 'the extra deny hid it');

        Assert::true(!$after || $before, 'an added deny pattern made a hidden tool visible');
    }

    /**
     * Adding an allow pattern to an ALREADY non-empty allow list can only
     * widen it. (Going from empty to non-empty legitimately hides things —
     * that is the documented switch from deny-list to allow-list mode, and
     * this property deliberately does not cover it.)
     *
     * @param list<string> $allow
     * @param list<string> $tags
     */
    #[Property(runs: 400, timeoutMs: 500)]
    public function addingAnAllowPatternNeverHidesATool(array $allow, string $extra, string $name, array $tags): void
    {
        $tool = $this->toolWithTags($name, $tags);
        $allow = $allow === [] ? ['nothing.matches.this'] : $allow;

        $before = (new DeclarativeToolVisibility(allow: $allow))->isVisible($tool, null);
        $after = (new DeclarativeToolVisibility(allow: [...$allow, $extra]))->isVisible($tool, null);

        Classify::cover($before, 'visible before the extra allow', 10.0);
        Classify::when(!$before && $after, 'the extra allow revealed it');

        Assert::true(!$before || $after, 'an added allow pattern hid a visible tool');
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function denyAlwaysBeatsAllowGenerators(): array
    {
        return [
            'deny' => self::patternListGenerator(),
            'allow' => self::patternListGenerator(),
            'name' => self::toolNameGenerator(),
            'tags' => self::tagListGenerator(),
        ];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function anEmptyAllowListMeansEverythingNotDeniedGenerators(): array
    {
        return [
            'deny' => self::patternListGenerator(),
            'name' => self::toolNameGenerator(),
            'tags' => self::tagListGenerator(),
        ];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function addingADenyPatternNeverRevealsAToolGenerators(): array
    {
        return [
            'deny' => self::patternListGenerator(),
            'allow' => self::patternListGenerator(),
            'extra' => self::patternGenerator(),
            'name' => self::toolNameGenerator(),
            'tags' => self::tagListGenerator(),
        ];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function addingAnAllowPatternNeverHidesAToolGenerators(): array
    {
        return [
            'allow' => self::patternListGenerator(),
            'extra' => self::patternGenerator(),
            'name' => self::toolNameGenerator(),
            'tags' => self::tagListGenerator(),
        ];
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, string, list<string>}>
     */
    public static function denyAlwaysBeatsAllowExamples(): iterable
    {
        yield 'exact deny under a matching allow' => [['admin.reset'], ['admin.*'], 'admin.reset', []];
        yield 'bare wildcard deny' => [['*'], ['*'], 'anything', []];
        yield 'tag deny under a name allow' => [['tag:admin'], ['order.*'], 'order.delete', ['admin']];
        yield 'untagged tool escapes a tag deny' => [['tag:admin'], [], 'order.delete', []];
        // the tag: prefix is consumed once, so a tool literally named
        // "tag:admin" is not matched by a tag pattern of the same spelling
        yield 'literal tag-looking name' => [['tag:admin'], [], 'tag:admin', []];
    }

    private static function patternGenerator(): ArbitraryInterface
    {
        return Gen::elements([
            '*',
            'admin.*',
            '*.delete',
            'order.*.force',
            'admin.reset',
            'order.delete',
            'tag:*',
            'tag:admin',
            'tag:admin-*',
            'tag:beta',
        ]);
    }

    private static function patternListGenerator(): ArbitraryInterface
    {
        return Gen::arrayOf(self::patternGenerator(), 0, 3);
    }

    private static function toolNameGenerator(): ArbitraryInterface
    {
        return Gen::elements([
            'admin.reset',
            'admin.purge',
            'order.delete',
            'order.cancel.force',
            'order.status',
            'tag:admin',
            'weather',
        ]);
    }

    private static function tagListGenerator(): ArbitraryInterface
    {
        return Gen::frequency([
            [2, Gen::constant([])],
            [3, Gen::uniqueArrayOf(Gen::elements(['admin', 'admin-write', 'beta', 'public']), 1, 3)],
        ]);
    }

    private function tool(string $name): Tool
    {
        return new Tool(
            name: $name,
            title: null,
            inputSchema: ['type' => 'object'],
            description: null,
            annotations: null,
        );
    }

    /**
     * @param list<string> $tags
     */
    private function toolWithTags(string $name, array $tags): Tool
    {
        return new Tool(
            name: $name,
            title: null,
            inputSchema: ['type' => 'object'],
            description: null,
            annotations: null,
            meta: ['rasuvaeff/yii3-mcp' => ['tags' => $tags]],
        );
    }
}
