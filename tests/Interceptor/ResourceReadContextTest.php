<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Rasuvaeff\Yii3Mcp\Interceptor\ResourceReadContext;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeSession;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ResourceReadContext::class)]
final class ResourceReadContextTest
{
    public function clientInfoComesFromTheSession(): void
    {
        $session = new FakeSession(['client_info' => ['name' => 'claude', 'version' => '2.0']]);
        $context = new ResourceReadContext(uri: 'app://x', session: $session);

        Assert::same($context->clientInfo()?->name, 'claude');
        Assert::same($context->clientInfo()?->version, '2.0');
    }

    public function clientInfoIsEmptyWithoutASession(): void
    {
        $context = new ResourceReadContext(uri: 'app://x');

        Assert::null($context->clientInfo());
        Assert::same($context->variables, []);
        Assert::null($context->uriTemplate);
    }

    public function clientInfoIsEmptyWhenSessionValueIsNotAnArray(): void
    {
        $session = new FakeSession(['client_info' => 'corrupted']);
        $context = new ResourceReadContext(uri: 'app://x', session: $session);

        Assert::null($context->clientInfo());
    }
}
