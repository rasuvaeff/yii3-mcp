<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Visibility;

use InvalidArgumentException;
use Mcp\Schema\Tool;
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
