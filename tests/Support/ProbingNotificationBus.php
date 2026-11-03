<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Schema\JsonRpc\Notification;
use Mcp\Server\Subscription\NotificationBusInterface;

/**
 * A bus that counts the times a subscriptions/listen stream starts reading
 * it: cursor() is the first thing a stream asks. It then throws — the SDK
 * ends a stream on a failing bus — so a test does not wait out the stream's
 * lifetime, whatever that lifetime is configured to be.
 */
final class ProbingNotificationBus implements NotificationBusInterface
{
    public int $cursorCalls = 0;

    #[\Override]
    public function publish(Notification $notification): void {}

    #[\Override]
    public function cursor(): int
    {
        ++$this->cursorCalls;

        throw new \RuntimeException('a stream is listening on this bus');
    }

    #[\Override]
    public function since(int $cursor): array
    {
        return [[], $cursor];
    }
}
