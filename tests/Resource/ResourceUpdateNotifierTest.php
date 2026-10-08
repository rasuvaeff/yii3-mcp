<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Resource;

use Fiber;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\Request\PingRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Resource\SessionSubscriptionManager;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Subscription\InMemoryNotificationBus;
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

    public function aSubscribedHandshakeSessionIsReached(): void
    {
        Assert::true($this->notified($this->subscribedSession('app://counter'), 'app://counter'));
    }

    /**
     * Stateless listeners are reached through the bus: one publish, picked
     * up by every subscriptions/listen stream reading forward from the
     * cursor — whatever process holds it.
     */
    public function theBusCarriesTheUpdateToStatelessListeners(): void
    {
        $bus = new InMemoryNotificationBus();
        $cursor = $bus->cursor();

        (new ResourceUpdateNotifier(new SessionSubscriptionManager(), $bus))->notify('app://orders/42');

        [$published] = $bus->since($cursor);

        Assert::same(count($published), 1);
        Assert::instanceOf($published[0], ResourceUpdatedNotification::class);
        \assert($published[0] instanceof ResourceUpdatedNotification);
        Assert::same($published[0]->uri, 'app://orders/42');
    }

    /**
     * A stateless request's session is a throwaway: even if it claimed a
     * subscription, nothing is sent on it — its listeners get the bus copy.
     */
    public function aStatelessCallingSessionIsNotNotifiedDirectly(): void
    {
        $session = $this->subscribedSession('app://counter');
        $session->set(RequestMeta::class, new RequestMeta('2026-07-28', new ClientCapabilities()));

        Assert::false($this->notified($session, 'app://counter'));
    }

    /**
     * Both halves for a handshake call with a bus: the calling session on
     * its stream, every stateless listener through the bus.
     */
    public function aHandshakeCallWithABusReachesBoth(): void
    {
        $bus = new InMemoryNotificationBus();
        $cursor = $bus->cursor();
        $notifier = new ResourceUpdateNotifier(new SessionSubscriptionManager(), $bus);
        $context = $this->context($this->subscribedSession('app://counter'));

        $fiber = new Fiber(static fn() => $notifier->notify('app://counter', $context));
        $fiber->start();

        Assert::true($fiber->isSuspended());
        Assert::same(count($bus->since($cursor)[0]), 1);
    }

    /**
     * Without a request at all — a queue worker, a console command — only the
     * bus half applies, and it needs no session.
     */
    public function anOutOfBandUpdateNeedsNoRequest(): void
    {
        $bus = new InMemoryNotificationBus();
        $cursor = $bus->cursor();

        (new ResourceUpdateNotifier(new SessionSubscriptionManager(), $bus))->notify('app://counter');
        (new ResourceUpdateNotifier(new SessionSubscriptionManager()))->notify('app://counter');

        Assert::same(count($bus->since($cursor)[0]), 1);
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
        $fiber = new Fiber(fn() => $this->notifier()->notify($uri, $this->context($session)));
        /** @var mixed $suspended */
        $suspended = $fiber->start();

        return is_array($suspended) ? $suspended : null;
    }

    /**
     * Whether the calling session was sent anything: the gateway sends by
     * suspending the Fiber.
     */
    private function notified(SessionInterface $session, string $uri): bool
    {
        $notifier = $this->notifier();
        $context = $this->context($session);
        $fiber = new Fiber(static fn() => $notifier->notify($uri, $context));
        $fiber->start();

        return $fiber->isSuspended();
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
