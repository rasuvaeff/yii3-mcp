<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests;

use Mcp\Schema\Request\CallToolRequest;
use Rasuvaeff\Yii3Mcp\ClientBoundRequestStateBinder;
use Rasuvaeff\Yii3Mcp\Identity\ClientIdentityContext;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Test]
#[Covers(ClientBoundRequestStateBinder::class)]
final class ClientBoundRequestStateBinderTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        ClientIdentityContext::disarm();
    }

    public function theSameRequestOfTheSameClientBindsTheSame(): void
    {
        Assert::same($this->bind('a', 'order.rename', ['orderId' => '42']), $this->bind('a', 'order.rename', ['orderId' => '42']));
    }

    public function anotherClientBindsDifferently(): void
    {
        Assert::notSame($this->bind('a', 'order.rename', ['orderId' => '42']), $this->bind('b', 'order.rename', ['orderId' => '42']));
        Assert::notSame($this->bind('a', 'order.rename', ['orderId' => '42']), $this->bind(null, 'order.rename', ['orderId' => '42']));
    }

    public function anotherToolOrOtherArgumentsBindDifferently(): void
    {
        Assert::notSame($this->bind('a', 'order.rename', ['orderId' => '42']), $this->bind('a', 'order.delete', ['orderId' => '42']));
        Assert::notSame($this->bind('a', 'order.rename', ['orderId' => '42']), $this->bind('a', 'order.rename', ['orderId' => '43']));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function bind(?string $clientId, string $tool, array $arguments): string
    {
        ClientIdentityContext::arm($clientId);

        return (new ClientBoundRequestStateBinder())->bind(new CallToolRequest($tool, $arguments), null);
    }
}
