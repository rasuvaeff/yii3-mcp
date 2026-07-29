<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Server\Protocol;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\SessionInterface;

final class RecordingSubscriptionManager implements SubscriptionManagerInterface
{
    /** @var list<string> */
    public array $subscribed = [];

    /** @var list<string> */
    public array $unsubscribed = [];

    #[\Override]
    public function subscribe(SessionInterface $session, string $uri): void
    {
        $this->subscribed[] = $uri;
    }

    #[\Override]
    public function unsubscribe(SessionInterface $session, string $uri): void
    {
        $this->unsubscribed[] = $uri;
    }

    #[\Override]
    public function isSubscribed(SessionInterface $session, string $uri): bool
    {
        return in_array($uri, $this->subscribed, strict: true);
    }

    #[\Override]
    public function notifyResourceChanged(Protocol $protocol, SessionInterface $session, string $uri): void {}
}
