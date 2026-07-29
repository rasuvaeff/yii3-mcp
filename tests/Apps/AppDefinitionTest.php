<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Apps;

use InvalidArgumentException;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Rasuvaeff\Yii3Mcp\Apps\AppDefinition;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(AppDefinition::class)]
final class AppDefinitionTest
{
    public function createKeepsEveryField(): void
    {
        $meta = new UiResourceContentMeta(prefersBorder: true);

        $app = AppDefinition::create(
            uri: 'ui://dashboard',
            name: 'dashboard',
            html: '<h1>Hi</h1>',
            title: 'Dashboard',
            description: 'Sales overview',
            contentMeta: $meta,
        );

        Assert::same($app->uri, 'ui://dashboard');
        Assert::same($app->name, 'dashboard');
        Assert::same($app->title, 'Dashboard');
        Assert::same($app->description, 'Sales overview');
        Assert::same($app->contentMeta, $meta);
    }

    public function optionalFieldsDefaultToNull(): void
    {
        $app = AppDefinition::create(uri: 'ui://plain', name: 'plain', html: '');

        Assert::null($app->title);
        Assert::null($app->description);
        Assert::null($app->contentMeta);
        Assert::same($app->renderHtml(), '');
    }

    public function stringHtmlIsPreservedAndRendered(): void
    {
        $app = AppDefinition::create(uri: 'ui://static', name: 'static', html: '<h1>Static</h1>');

        Assert::same($app->html, '<h1>Static</h1>');
        Assert::same($app->renderHtml(), '<h1>Static</h1>');
    }

    public function closureHtmlIsEvaluatedOnEveryRender(): void
    {
        $calls = 0;
        $app = AppDefinition::create(
            uri: 'ui://dynamic',
            name: 'dynamic',
            html: static function () use (&$calls): string {
                ++$calls;

                return '<h1>' . $calls . '</h1>';
            },
        );

        Assert::same($app->renderHtml(), '<h1>1</h1>');
        Assert::same($app->renderHtml(), '<h1>2</h1>');
        Assert::same($calls, 2);
    }

    #[DataProvider('invalidUriProvider')]
    public function rejectsUriOutsideTheUiScheme(string $uri): void
    {
        Expect::exception(InvalidArgumentException::class);

        AppDefinition::create(uri: $uri, name: 'app', html: '');
    }

    public static function invalidUriProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'other scheme' => ['app://dashboard'];
        yield 'scheme without separator' => ['ui:dashboard'];
        yield 'bare scheme' => ['ui://'];
        yield 'scheme not at the start' => ['x-ui://dashboard'];
    }

    public function rejectsEmptyName(): void
    {
        $caught = null;

        try {
            AppDefinition::create(uri: 'ui://dashboard', name: '', html: '');
        } catch (InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), 'App name must not be empty');
    }

    public function invalidUriMessageNamesTheValueAndThePrefix(): void
    {
        $caught = null;

        try {
            AppDefinition::create(uri: 'app://dashboard', name: 'app', html: '');
        } catch (InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())
            ->contains('app://dashboard')
            ->contains('ui://');
    }
}
