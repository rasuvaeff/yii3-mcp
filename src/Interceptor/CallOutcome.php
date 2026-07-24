<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Mcp\Exception\PromptGetException;
use Mcp\Exception\ResourceReadException;
use Mcp\Exception\ToolCallException;
use Throwable;

/**
 * The shared outcome vocabulary for audit/telemetry bridges, so a rate-limit
 * rejection is never counted as a tool crash and every bridge classifies
 * identically:
 *
 * - `Success` — the handler ran and returned;
 * - `Rejected` — the call was refused with a client-visible message
 *   ({@see ToolCallException} / {@see PromptGetException} /
 *   {@see ResourceReadException}), by an interceptor (RBAC, rate limit,
 *   budget) or by the capability itself;
 * - `Error` — any other exception: an unexpected failure the client sees
 *   only as an opaque internal error.
 *
 * @api
 */
enum CallOutcome: string
{
    case Success = 'success';
    case Rejected = 'rejected';
    case Error = 'error';

    public static function fromThrowable(Throwable $exception): self
    {
        return $exception instanceof ToolCallException
            || $exception instanceof PromptGetException
            || $exception instanceof ResourceReadException
            ? self::Rejected
            : self::Error;
    }
}
