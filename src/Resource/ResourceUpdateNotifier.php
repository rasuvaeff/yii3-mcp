<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Resource;

use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\RequestContext;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Subscription\NotificationBusInterface;
use Rasuvaeff\Yii3Mcp\Interceptor\RequestEra;

/**
 * Tells subscribed clients that a resource has changed — on both protocol
 * eras, which reach their subscribers in different ways:
 *
 * - Stateless 2026-07-28 era: clients hold a `subscriptions/listen` stream,
 *   fed from a {@see NotificationBusInterface}. Publishing there reaches EVERY
 *   listener whose filter names the URI, from any process sharing the bus —
 *   including a queue worker or a console command, so `$context` is optional.
 *   Without a bus configured (`notifications.bus`) this half is a no-op.
 * - Handshake era: the subscription lives in the caller's session, and only
 *   that session's stream is reachable from inside the request that changed
 *   the resource. With a `$context` from a handshake-era request, a subscribed
 *   calling session is notified on its own stream; other handshake sessions
 *   are not reached — no process holds their connections.
 *
 * ```php
 * #[McpTool(name: 'order.cancel')]
 * public function cancel(string $orderId, RequestContext $context): string
 * {
 *     $this->orders->cancel($orderId);
 *     $this->notifier->notify('app://orders/' . $orderId, $context);
 *
 *     return 'cancelled';
 * }
 * ```
 *
 * An unsolicited `notifications/resources/updated` never appears on the
 * wire: the session path checks the subscription first, and a listen stream
 * carries only the URIs its own filter asked for.
 *
 * @api
 */
final readonly class ResourceUpdateNotifier
{
    public function __construct(
        private SubscriptionManagerInterface $subscriptions,
        private ?NotificationBusInterface $bus = null,
    ) {}

    public function notify(string $uri, ?RequestContext $context = null): void
    {
        $notification = new ResourceUpdatedNotification($uri);

        $this->bus?->publish($notification);

        if (!$context instanceof RequestContext) {
            return;
        }

        $session = $context->getSession();

        // a stateless request's session is a throwaway: nothing subscribed in
        // it, and its listeners are reached through the bus above
        if (RequestEra::isModern($session) || !$this->subscriptions->isSubscribed($session, $uri)) {
            return;
        }

        $context->getClientGateway()->notify($notification);
    }
}
