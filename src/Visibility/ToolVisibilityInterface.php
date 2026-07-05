<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Visibility;

use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;

/**
 * Per-session tool visibility: decides which tools THIS session may see and
 * call — based on the session's initialize handshake (`client_info`), tenant
 * or any other session data. The complement of ConditionalToolInterface,
 * which gates registration globally at build time.
 *
 * Applied in two places, consistently: tools/list filters the listing, and
 * tools/call fail-closed rejects a call to an invisible tool (a client that
 * guesses a hidden name still cannot call it).
 *
 * This is an early filter, not a replacement for application-level ACL.
 *
 * @api
 */
interface ToolVisibilityInterface
{
    public function isVisible(Tool $tool, ?SessionInterface $session): bool;
}
