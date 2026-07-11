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
        Assert::true((new DeclarativeToolVisibility())->isVisible(self::tool('anything'), null));
    }

    #[DataProvider('denyProvider')]
    public function denyPatternHidesMatchingTools(string $pattern, string $toolName, bool $visible): void
    {
        $visibility = new DeclarativeToolVisibility(deny: [$pattern]);

        Assert::same($visibility->isVisible(self::tool($toolName), null), $visible);
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
    }

    public function nonEmptyAllowListHidesEverythingElse(): void
    {
        $visibility = new DeclarativeToolVisibility(allow: ['order.*', 'greet']);

        Assert::true($visibility->isVisible(self::tool('order.status'), null));
        Assert::true($visibility->isVisible(self::tool('greet'), null));
        Assert::false($visibility->isVisible(self::tool('admin.reset'), null));
    }

    public function denyWinsOverAllow(): void
    {
        $visibility = new DeclarativeToolVisibility(deny: ['order.delete'], allow: ['order.*']);

        Assert::false($visibility->isVisible(self::tool('order.delete'), null));
        Assert::true($visibility->isVisible(self::tool('order.status'), null));
    }

    public function throwsOnEmptyPattern(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new DeclarativeToolVisibility(deny: ['']);
    }

    private static function tool(string $name): Tool
    {
        return new Tool(
            name: $name,
            title: null,
            inputSchema: ['type' => 'object'],
            description: null,
            annotations: null,
        );
    }
}
