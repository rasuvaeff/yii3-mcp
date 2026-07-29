<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Apps;

use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;
use Mcp\Server\ClientGateway;
use Rasuvaeff\Yii3Mcp\Apps\AppDefinition;
use Rasuvaeff\Yii3Mcp\Apps\AppResourceHandler;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeSession;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(AppResourceHandler::class)]
final class AppResourceHandlerTest
{
    public function servesTheHtmlAsResourceContentsCarryingTheSandboxContract(): void
    {
        $meta = new UiResourceContentMeta(
            csp: new UiResourceCsp(connectDomains: ['api.example.com']),
            prefersBorder: true,
        );

        $contents = $this->read(AppDefinition::create(
            uri: 'ui://dashboard',
            name: 'dashboard',
            html: '<h1>Sales</h1>',
            contentMeta: $meta,
        ));

        // a plain string would lose _meta, a ReadResourceResult would not be
        // formattable at all — the ResourceContents shape is load-bearing
        Assert::instanceOf($contents, TextResourceContents::class);
        Assert::same($contents->text, '<h1>Sales</h1>');
        Assert::same($contents->uri, 'ui://dashboard');
        Assert::same($contents->mimeType, 'text/html;profile=mcp-app');
        Assert::same($contents->meta, ['ui' => $meta]);
    }

    public function contentHasNoMetaWithoutASandboxContract(): void
    {
        $contents = $this->read(AppDefinition::create(uri: 'ui://plain', name: 'plain', html: '<h1>Plain</h1>'));

        Assert::null($contents->meta);
    }

    public function htmlIsRenderedOnEveryRead(): void
    {
        $calls = 0;
        $app = AppDefinition::create(
            uri: 'ui://counter',
            name: 'counter',
            html: static function () use (&$calls): string {
                ++$calls;

                return '<h1>' . $calls . '</h1>';
            },
        );

        Assert::same($this->read($app)->text, '<h1>1</h1>');
        Assert::same($this->read($app)->text, '<h1>2</h1>');
    }

    public function contentUriComesFromTheDefinitionNotTheRequestedUri(): void
    {
        $handler = new AppResourceHandler(AppDefinition::create(uri: 'ui://real', name: 'real', html: ''));

        /** @var TextResourceContents $contents */
        $contents = $handler->read('ui://something-else', new ClientGateway(new FakeSession()));

        Assert::same($contents->uri, 'ui://real');
    }

    private function read(AppDefinition $app): TextResourceContents
    {
        $contents = (new AppResourceHandler($app))->read($app->uri, new ClientGateway(new FakeSession()));

        Assert::instanceOf($contents, TextResourceContents::class);
        \assert($contents instanceof TextResourceContents);

        return $contents;
    }
}
