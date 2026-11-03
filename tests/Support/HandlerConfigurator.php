<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Server\Builder;
use Mcp\Server\Handler\Notification\NotificationHandlerInterface;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Rasuvaeff\Yii3Mcp\ServerConfiguratorInterface;

/**
 * Puts custom request and notification handlers in front of the SDK's own.
 */
final readonly class HandlerConfigurator implements ServerConfiguratorInterface
{
    /**
     * @param list<RequestHandlerInterface<mixed>> $requestHandlers
     * @param list<NotificationHandlerInterface> $notificationHandlers
     */
    public function __construct(
        private array $requestHandlers = [],
        private array $notificationHandlers = [],
    ) {}

    #[\Override]
    public function configure(Builder $builder): void
    {
        $builder->addRequestHandlers($this->requestHandlers);
        $builder->addNotificationHandlers($this->notificationHandlers);
    }
}
