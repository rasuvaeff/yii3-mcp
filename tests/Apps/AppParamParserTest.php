<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Apps;

use InvalidArgumentException;
use Rasuvaeff\Yii3Mcp\Apps\AppParamParser;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(AppParamParser::class)]
final class AppParamParserTest
{
    public function fullShapeReachesTheDefinition(): void
    {
        $app = AppParamParser::parse([
            'uri' => 'ui://dashboard',
            'name' => 'dashboard',
            'html' => '<h1>Hi</h1>',
            'title' => 'Dashboard',
            'description' => 'Sales overview',
            'csp' => ['connect_domains' => ['api.example.com']],
            'permissions' => ['geolocation' => true],
            'domain' => 'apps.example.com',
            'prefers_border' => true,
        ]);

        Assert::same($app->uri, 'ui://dashboard');
        Assert::same($app->name, 'dashboard');
        Assert::same($app->renderHtml(), '<h1>Hi</h1>');
        Assert::same($app->title, 'Dashboard');
        Assert::same($app->description, 'Sales overview');
        Assert::same($app->contentMeta?->domain, 'apps.example.com');
        Assert::same($app->contentMeta?->prefersBorder, true);
        Assert::same($app->contentMeta?->csp?->connectDomains, ['api.example.com']);
        Assert::same($app->contentMeta?->permissions?->geolocation, true);
    }

    /**
     * The SDK's UiResourcePermissions::fromArray() would read a PRESENT key as
     * "requested" and turn `false` into an enabled permission; the params
     * format is value-based, so the constructor is used instead.
     */
    public function falsePermissionStaysOff(): void
    {
        $app = AppParamParser::parse([
            'uri' => 'ui://a',
            'name' => 'a',
            'html' => '',
            'permissions' => ['camera' => false, 'geolocation' => true],
        ]);

        Assert::same($app->contentMeta?->permissions?->camera, false);
        Assert::same($app->contentMeta?->permissions?->geolocation, true);
    }

    public function permissionsSerializeAsPresenceMarkers(): void
    {
        $app = AppParamParser::parse([
            'uri' => 'ui://a',
            'name' => 'a',
            'html' => '',
            'permissions' => ['camera' => false, 'clipboard_write' => true],
        ]);

        Assert::same(
            json_encode($app->contentMeta?->permissions, JSON_THROW_ON_ERROR),
            '{"clipboardWrite":{}}',
        );
    }

    public function absentPermissionKeysStayOff(): void
    {
        $app = AppParamParser::parse([
            'uri' => 'ui://a',
            'name' => 'a',
            'html' => '',
            'permissions' => ['geolocation' => true],
        ]);

        $permissions = $app->contentMeta?->permissions;

        Assert::same($permissions?->camera, false);
        Assert::same($permissions?->microphone, false);
        Assert::same($permissions?->clipboardWrite, false);
        Assert::same(json_encode($permissions, JSON_THROW_ON_ERROR), '{"geolocation":{}}');
    }

    public function everyPermissionKeyIsMapped(): void
    {
        $app = AppParamParser::parse([
            'uri' => 'ui://a',
            'name' => 'a',
            'html' => '',
            'permissions' => [
                'camera' => true,
                'microphone' => true,
                'geolocation' => true,
                'clipboard_write' => true,
            ],
        ]);

        $permissions = $app->contentMeta?->permissions;

        Assert::same($permissions?->camera, true);
        Assert::same($permissions?->microphone, true);
        Assert::same($permissions?->geolocation, true);
        Assert::same($permissions?->clipboardWrite, true);
    }

    /**
     * Params are snake_case throughout this package; the SDK VO is camelCase.
     * Feeding the params array to UiResourceCsp::fromArray() would silently
     * yield an all-null CSP — an allow-list the operator believes exists.
     */
    public function snakeCaseCspKeysReachTheCamelCaseFields(): void
    {
        $app = AppParamParser::parse([
            'uri' => 'ui://a',
            'name' => 'a',
            'html' => '',
            'csp' => [
                'connect_domains' => ['api.example.com'],
                'resource_domains' => ['cdn.example.com'],
                'frame_domains' => ['embed.example.com'],
                'base_uri_domains' => ['example.com'],
            ],
        ]);

        $csp = $app->contentMeta?->csp;

        Assert::same($csp?->connectDomains, ['api.example.com']);
        Assert::same($csp?->resourceDomains, ['cdn.example.com']);
        Assert::same($csp?->frameDomains, ['embed.example.com']);
        Assert::same($csp?->baseUriDomains, ['example.com']);
    }

    public function unsetCspListsStayNullAndAreDroppedFromJson(): void
    {
        $app = AppParamParser::parse([
            'uri' => 'ui://a',
            'name' => 'a',
            'html' => '',
            'csp' => ['connect_domains' => ['api.example.com'], 'frame_domains' => []],
        ]);

        Assert::null($app->contentMeta?->csp?->frameDomains);
        Assert::same(
            json_encode($app->contentMeta?->csp, JSON_THROW_ON_ERROR),
            '{"connectDomains":["api.example.com"]}',
        );
    }

    #[DataProvider('metalessProvider')]
    public function contentMetaStaysNullWithoutAnyMetaKey(array $extra): void
    {
        $app = AppParamParser::parse(['uri' => 'ui://a', 'name' => 'a', 'html' => ''] + $extra);

        Assert::null($app->contentMeta);
    }

    public static function metalessProvider(): iterable
    {
        yield 'nothing' => [[]];
        yield 'empty csp' => [['csp' => []]];
        yield 'empty permissions' => [['permissions' => []]];
        yield 'non-array csp' => [['csp' => 'nope']];
        yield 'null prefers_border' => [['prefers_border' => null]];
    }

    public function closureHtmlIsAccepted(): void
    {
        $app = AppParamParser::parse([
            'uri' => 'ui://a',
            'name' => 'a',
            'html' => static fn(): string => '<h1>Dynamic</h1>',
        ]);

        Assert::same($app->renderHtml(), '<h1>Dynamic</h1>');
    }

    #[DataProvider('invalidHtmlProvider')]
    public function rejectsHtmlThatIsNeitherStringNorClosure(mixed $html): void
    {
        Expect::exception(InvalidArgumentException::class);

        AppParamParser::parse(['uri' => 'ui://a', 'name' => 'a', 'html' => $html]);
    }

    public static function invalidHtmlProvider(): iterable
    {
        yield 'int' => [42];
        yield 'missing' => [null];
        yield 'array' => [['<h1>']];
    }

    #[DataProvider('missingRequiredProvider')]
    public function rejectsMissingRequiredKeys(array $app, string $expectedKey): void
    {
        $caught = null;

        try {
            AppParamParser::parse($app);
        } catch (InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains($expectedKey);
    }

    public static function missingRequiredProvider(): iterable
    {
        yield 'no uri' => [['name' => 'a', 'html' => ''], 'uri'];
        yield 'empty uri' => [['uri' => '', 'name' => 'a', 'html' => ''], 'uri'];
        yield 'no name' => [['uri' => 'ui://a', 'html' => ''], 'name'];
        yield 'non-string name' => [['uri' => 'ui://a', 'name' => 7, 'html' => ''], 'name'];
    }

    public function rejectsNonStringCspDomain(): void
    {
        $caught = null;

        try {
            AppParamParser::parse([
                'uri' => 'ui://a',
                'name' => 'a',
                'html' => '',
                'csp' => ['connect_domains' => ['ok.example.com', 42]],
            ]);
        } catch (InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('CSP domains must be strings');
    }

    public function nonStringOptionalsAreIgnored(): void
    {
        $app = AppParamParser::parse([
            'uri' => 'ui://a',
            'name' => 'a',
            'html' => '',
            'title' => 42,
            'description' => ['x'],
            'domain' => 7,
        ]);

        Assert::null($app->title);
        Assert::null($app->description);
        Assert::null($app->contentMeta);
    }
}
