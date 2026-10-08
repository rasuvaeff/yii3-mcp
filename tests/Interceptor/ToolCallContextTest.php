<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Mcp\Schema\ClientCapabilities;
use Mcp\Server\Stateless\RequestMeta;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeSession;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ToolCallContext::class)]
final class ToolCallContextTest
{
    public function clientInfoComesFromTheSession(): void
    {
        $session = new FakeSession(['client_info' => ['name' => 'claude', 'version' => '2.0']]);
        $context = new ToolCallContext(toolName: 'x', arguments: [], session: $session);

        Assert::same($context->clientInfo()?->name, 'claude');
        Assert::same($context->clientInfo()?->version, '2.0');
    }

    public function clientInfoIsEmptyWithoutASession(): void
    {
        $context = new ToolCallContext(toolName: 'x', arguments: []);

        Assert::null($context->clientInfo());
    }

    public function clientInfoIsEmptyWhenSessionValueIsNotAnArray(): void
    {
        $session = new FakeSession(['client_info' => 'corrupted']);
        $context = new ToolCallContext(toolName: 'x', arguments: [], session: $session);

        Assert::null($context->clientInfo());
    }

    public function statelessIsReadFromTheRequestMeta(): void
    {
        $meta = new RequestMeta('2026-07-28', new ClientCapabilities());

        Assert::true((new ToolCallContext(toolName: 'x', arguments: [], session: new FakeSession([RequestMeta::class => $meta])))->isStateless());
        Assert::false((new ToolCallContext(toolName: 'x', arguments: [], session: new FakeSession()))->isStateless());
        Assert::false((new ToolCallContext(toolName: 'x', arguments: []))->isStateless());
    }
}
