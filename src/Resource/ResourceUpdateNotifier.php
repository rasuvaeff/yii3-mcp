<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Resource;

use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\RequestContext;
use Mcp\Server\Resource\SubscriptionManagerInterface;

/**
 * Tells the calling client that a resource it subscribed to has changed.
 *
 * The MCP server advertises `resources.subscribe` and records subscriptions
 * per session, but nothing emits `notifications/resources/updated` on its own —
 * and under PHP-FPM nothing outlives the request to push from either. What IS
 * possible is notifying **inside** the request that caused the change: a tool
 * that writes the data behind a resource takes the SDK's request-scoped
 * `RequestContext` and calls this notifier, and the subscriber sees the update
 * on the same SSE stream.
 *
 * ```php
 * #[McpTool(name: 'order.cancel')]
 * public function cancel(string $orderId, RequestContext $context): string
 * {
 *     $this->orders->cancel($orderId);
 *     $this->notifier->notify($context, 'app://orders/' . $orderId);
 *
 *     return 'cancelled';
 * }
 * ```
 *
 * Only the session making the call is notified — the one whose gateway is
 * reachable. Other sessions subscribed to the same URI are NOT reached; that
 * would need a connection this process does not hold. A session that never
 * subscribed is not notified at all, so an unsolicited
 * `notifications/resources/updated` never appears on the wire.
 *
 * @api
 */
final readonly class ResourceUpdateNotifier
{
    public function __construct(
        private SubscriptionManagerInterface $subscriptions,
    ) {}

    /**
     * @return bool whether the calling session was subscribed and got notified
     */
    public function notify(RequestContext $context, string $uri): bool
    {
        if (!$this->subscriptions->isSubscribed($context->getSession(), $uri)) {
            return false;
        }

        $context->getClientGateway()->notify(new ResourceUpdatedNotification($uri));

        return true;
    }
}
