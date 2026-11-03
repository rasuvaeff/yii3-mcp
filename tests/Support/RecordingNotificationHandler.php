<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Schema\JsonRpc\Notification;
use Mcp\Server\Handler\Notification\NotificationHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Remembers the method of every notification the server receives.
 */
final class RecordingNotificationHandler implements NotificationHandlerInterface
{
    /**
     * @var list<string>
     */
    public array $methods = [];

    #[\Override]
    public function supports(Notification $notification): bool
    {
        return true;
    }

    #[\Override]
    public function handle(Notification $notification, SessionInterface $session): void
    {
        $this->methods[] = $notification::getMethod();
    }
}
