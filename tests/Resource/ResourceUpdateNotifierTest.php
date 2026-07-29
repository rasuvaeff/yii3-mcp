<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Resource;

use Fiber;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\Request\PingRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Resource\SessionSubscriptionManager;
use Mcp\Server\Session\SessionInterface;
use Rasuvaeff\Yii3Mcp\Resource\ResourceUpdateNotifier;
use Rasuvaeff\Yii3Mcp\Tests\Support\FakeSession;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The gateway sends by suspending the Fiber the SDK runs handlers in, so the
 * notifier is exercised inside a Fiber here and the suspend value — exactly
 * what the transport would flush — is asserted directly. Driving this through
 * McpTester is not possible: once a handler emits a notification the transport
 * switches to streaming and echoes the frames to output, leaving the PSR-7
 * body empty.
 */
#[Test]
#[Covers(ResourceUpdateNotifier::class)]
final class ResourceUpdateNotifierTest
{
    public function aSubscribedSessionGetsAResourceUpdatedNotification(): void
    {
        $session = $this->subscribedSession('app://counter');
        $sent = $this->send($session, 'app://counter');

        Assert::instanceOf($sent['notification'] ?? null, ResourceUpdatedNotification::class);
        Assert::same($sent['type'] ?? null, 'notification');
    }

    public function theNotificationCarriesTheChangedUri(): void
    {
        $sent = $this->send($this->subscribedSession('app://orders/42'), 'app://orders/42');
        $notification = $sent['notification'] ?? null;

        Assert::instanceOf($notification, ResourceUpdatedNotification::class);
        \assert($notification instanceof ResourceUpdatedNotification);
        Assert::same($notification->uri, 'app://orders/42');
    }

    /**
     * An unsolicited notifications/resources/updated must never reach a client
     * that did not ask for it: without a subscription nothing is sent at all,
     * so the Fiber runs to completion instead of suspending.
     */
    public function anUnsubscribedSessionIsNotNotified(): void
    {
        Assert::null($this->send(new FakeSession(), 'app://counter'));
        Assert::false($this->notified(new FakeSession(), 'app://counter'));
    }

    public function aSubscriptionToAnotherUriDoesNotFire(): void
    {
        Assert::false($this->notified($this->subscribedSession('app://other'), 'app://counter'));
    }

    public function unsubscribingStopsTheNotifications(): void
    {
        $session = $this->subscribedSession('app://counter');
        (new SessionSubscriptionManager())->unsubscribe($session, 'app://counter');

        Assert::false($this->notified($session, 'app://counter'));
    }

    public function notifyingReportsThatTheClientWasReached(): void
    {
        Assert::true($this->notified($this->subscribedSession('app://counter'), 'app://counter'));
    }

    private function subscribedSession(string $uri): SessionInterface
    {
        $session = new FakeSession();
        (new SessionSubscriptionManager())->subscribe($session, $uri);

        return $session;
    }

    /**
     * @return array<string, mixed>|null the value the gateway suspended with, or null if nothing was sent
     */
    private function send(SessionInterface $session, string $uri): ?array
    {
        $fiber = new Fiber(fn(): bool => $this->notifier()->notify($this->context($session), $uri));
        /** @var mixed $suspended */
        $suspended = $fiber->start();

        return is_array($suspended) ? $suspended : null;
    }

    private function notified(SessionInterface $session, string $uri): bool
    {
        $notifier = $this->notifier();
        $context = $this->context($session);
        $fiber = new Fiber(static fn(): bool => $notifier->notify($context, $uri));
        $fiber->start();

        if ($fiber->isSuspended()) {
            $fiber->resume();
        }

        return $fiber->getReturn();
    }

    private function notifier(): ResourceUpdateNotifier
    {
        return new ResourceUpdateNotifier(new SessionSubscriptionManager());
    }

    private function context(SessionInterface $session): RequestContext
    {
        return new RequestContext($session, new PingRequest());
    }
}
