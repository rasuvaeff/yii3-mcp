<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Exception;

use RuntimeException;

/**
 * A capability call arrived with a client identity different from the one the
 * session was created by. The session-to-client binding is immutable — this is
 * either a hijack attempt with a leaked session id or a broken embedding, and
 * both must fail closed.
 *
 * @api
 */
final class SessionOwnershipException extends RuntimeException {}
