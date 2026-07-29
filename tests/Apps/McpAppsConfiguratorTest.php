<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Apps;

use Mcp\Exception\LogicException;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;
use Mcp\Schema\Extension\Apps\UiResourcePermissions;
use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Mcp\Apps\AppDefinition;
use Rasuvaeff\Yii3Mcp\Apps\McpAppsConfigurator;
use Rasuvaeff\Yii3Mcp\Exception\DuplicateCapabilityException;
use Rasuvaeff\Yii3Mcp\McpServerFactory;
use Rasuvaeff\Yii3Mcp\Testing\McpTester;
use Rasuvaeff\Yii3Mcp\Tests\Support\DashboardAppTool;
use Rasuvaeff\Yii3Mcp\Tests\Support\DenyResourceVisibility;
use Rasuvaeff\Yii3Mcp\Visibility\ResourceVisibilityInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(McpAppsConfigurator::class)]
final class McpAppsConfiguratorTest
{
    public function extensionIsAdvertisedOnInitialize(): void
    {
        $capabilities = (array) ($this->tester()->initialize()['capabilities'] ?? []);
        $extensions = (array) ($capabilities['extensions'] ?? []);

        Assert::same(
            $extensions[McpApps::EXTENSION_ID] ?? null,
            ['mimeTypes' => ['text/html;profile=mcp-app']],
        );
    }

    public function extensionIsAdvertisedWithoutAnyDefinition(): void
    {
        $tester = $this->tester(apps: []);

        $capabilities = (array) ($tester->initialize()['capabilities'] ?? []);
        $extensions = (array) ($capabilities['extensions'] ?? []);

        Assert::true(isset($extensions[McpApps::EXTENSION_ID]));
        Assert::same($tester->listResources(), []);
    }

    public function descriptorCarriesTheMarkerAndTheAppMimeType(): void
    {
        $resource = $this->resourceByUri($this->tester(), 'ui://dashboard');

        Assert::same($resource['name'] ?? null, 'dashboard');
        Assert::same($resource['title'] ?? null, 'Dashboard');
        Assert::same($resource['description'] ?? null, 'Sales overview');
        Assert::same($resource['mimeType'] ?? null, 'text/html;profile=mcp-app');
        // the descriptor carries the bare marker; the sandbox contract lives
        // on the content, not here
        Assert::same($resource['_meta'] ?? null, ['ui' => []]);
    }

    public function contentCarriesTheHtmlAndTheSandboxContract(): void
    {
        $content = $this->firstContent($this->tester(), 'ui://dashboard');

        Assert::same($content['uri'] ?? null, 'ui://dashboard');
        Assert::same($content['mimeType'] ?? null, 'text/html;profile=mcp-app');
        Assert::same($content['text'] ?? null, '<!DOCTYPE html><h1>Sales</h1>');
        Assert::same($content['_meta'] ?? null, [
            'ui' => [
                'csp' => ['connectDomains' => ['api.example.com']],
                'permissions' => ['geolocation' => []],
                'prefersBorder' => true,
            ],
        ]);
    }

    public function contentHasNoMetaWithoutAContentMeta(): void
    {
        $content = $this->firstContent($this->tester(), 'ui://plain');

        Assert::same($content['text'] ?? null, '<h1>Plain</h1>');
        Assert::false(isset($content['_meta']));
    }

    public function closureHtmlIsReEvaluatedOnEveryRead(): void
    {
        $tester = $this->tester();

        Assert::same($this->firstContent($tester, 'ui://counter')['text'] ?? null, '<h1>1</h1>');
        Assert::same($this->firstContent($tester, 'ui://counter')['text'] ?? null, '<h1>2</h1>');
    }

    public function attributeAppKeepsItsOwnMetaOnBothLevels(): void
    {
        $tester = $this->attributeTester();

        Assert::same($this->resourceByUri($tester, 'ui://dashboard')['_meta'] ?? null, ['ui' => []]);
        Assert::same($this->firstContent($tester, 'ui://dashboard')['_meta'] ?? null, [
            'ui' => [
                'csp' => ['connectDomains' => ['api.example.com']],
                'prefersBorder' => true,
            ],
        ]);
    }

    public function toolLinkedToAnAppExposesItsResourceUri(): void
    {
        $tools = array_column($this->attributeTester()->listTools(), null, 'name');

        Assert::same($tools['refresh_dashboard']['_meta'] ?? null, [
            'ui' => ['resourceUri' => 'ui://dashboard'],
        ]);
    }

    public function declarativeUriCollidingWithAnAttributeResourceThrows(): void
    {
        $caught = null;

        try {
            $this->server(
                apps: [AppDefinition::create(uri: 'ui://dashboard', name: 'declarative', html: '<h1>Clash</h1>')],
                toolClasses: [DashboardAppTool::class],
            );
        } catch (\Throwable $caught) {
        }

        // the SDK's reflected loader wraps the guard's exception; the root
        // cause must still be the duplicate detection
        Assert::notNull($caught);
        $duplicate = $caught instanceof DuplicateCapabilityException ? $caught : $caught->getPrevious();
        Assert::instanceOf($duplicate, DuplicateCapabilityException::class);
        Assert::string($caught->getMessage())->contains('ui://dashboard');
    }

    public function enablingTheExtensionTwiceFailsTheBuild(): void
    {
        Expect::exception(LogicException::class);

        $this->server(apps: [], extraConfigurators: [new McpAppsConfigurator()]);
    }

    public function hiddenAppIsFilteredFromTheListing(): void
    {
        $tester = $this->tester(visibility: new DenyResourceVisibility(hiddenUris: ['ui://dashboard']));

        $uris = array_column($tester->listResources(), 'uri');

        Assert::false(in_array('ui://dashboard', $uris, strict: true));
        Assert::true(in_array('ui://plain', $uris, strict: true));
    }

    public function hiddenAppCannotBeReadByItsExactUri(): void
    {
        $tester = $this->tester(visibility: new DenyResourceVisibility(hiddenUris: ['ui://dashboard']));
        $caught = null;

        try {
            $tester->readResource('ui://dashboard');
        } catch (\RuntimeException $caught) {
        }

        Assert::notNull($caught);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function resourceByUri(McpTester $tester, string $uri): array
    {
        $resource = array_column($tester->listResources(), null, 'uri')[$uri] ?? [];

        return is_array($resource) ? $resource : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function firstContent(McpTester $tester, string $uri): array
    {
        $content = ((array) ($tester->readResource($uri)['contents'] ?? []))[0] ?? [];

        return is_array($content) ? $content : [];
    }

    /**
     * @param ?list<AppDefinition> $apps
     */
    private function tester(?array $apps = null, ?ResourceVisibilityInterface $visibility = null): McpTester
    {
        return $this->testerFor($this->server(apps: $apps ?? $this->apps(), visibility: $visibility));
    }

    private function attributeTester(): McpTester
    {
        return $this->testerFor($this->server(apps: [], toolClasses: [DashboardAppTool::class]));
    }

    private function testerFor(Server $server): McpTester
    {
        $factory = new Psr17Factory();

        return new McpTester(
            server: $server,
            requestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    /**
     * @param list<AppDefinition> $apps
     * @param list<class-string> $toolClasses
     * @param list<McpAppsConfigurator> $extraConfigurators
     */
    private function server(
        array $apps,
        array $toolClasses = [],
        array $extraConfigurators = [],
        ?ResourceVisibilityInterface $visibility = null,
    ): Server {
        return (new McpServerFactory(
            container: new SimpleContainer([DashboardAppTool::class => new DashboardAppTool()]),
            sessionStore: new InMemorySessionStore(),
            name: 'apps-suite',
            version: '1.0.0',
        ))->create(
            $toolClasses,
            [new McpAppsConfigurator($apps), ...$extraConfigurators],
            resourceVisibility: $visibility,
        );
    }

    /**
     * @return list<AppDefinition>
     */
    private function apps(): array
    {
        $calls = 0;

        return [
            AppDefinition::create(
                uri: 'ui://dashboard',
                name: 'dashboard',
                html: '<!DOCTYPE html><h1>Sales</h1>',
                title: 'Dashboard',
                description: 'Sales overview',
                contentMeta: new UiResourceContentMeta(
                    csp: new UiResourceCsp(connectDomains: ['api.example.com'], frameDomains: []),
                    permissions: new UiResourcePermissions(geolocation: true),
                    prefersBorder: true,
                ),
            ),
            AppDefinition::create(uri: 'ui://plain', name: 'plain', html: '<h1>Plain</h1>'),
            AppDefinition::create(
                uri: 'ui://counter',
                name: 'counter',
                html: static function () use (&$calls): string {
                    ++$calls;

                    return '<h1>' . $calls . '</h1>';
                },
            ),
        ];
    }
}
