<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Rasuvaeff\Yii3Mcp\Interceptor\PromptGetContext;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeSession;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PromptGetContext::class)]
final class PromptGetContextTest
{
    public function clientInfoComesFromTheSession(): void
    {
        $session = new FakeSession(['client_info' => ['name' => 'claude', 'version' => '2.0']]);
        $context = new PromptGetContext(promptName: 'x', arguments: [], session: $session);

        Assert::same($context->getClientInfo(), ['name' => 'claude', 'version' => '2.0']);
    }

    public function clientInfoIsEmptyWithoutASession(): void
    {
        $context = new PromptGetContext(promptName: 'x', arguments: []);

        Assert::same($context->getClientInfo(), []);
    }

    public function clientInfoIsEmptyWhenSessionValueIsNotAnArray(): void
    {
        $session = new FakeSession(['client_info' => 'corrupted']);
        $context = new PromptGetContext(promptName: 'x', arguments: [], session: $session);

        Assert::same($context->getClientInfo(), []);
    }
}
