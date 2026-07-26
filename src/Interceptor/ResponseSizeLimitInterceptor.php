<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use InvalidArgumentException;
use Mcp\Exception\ToolCallException;
use Rasuvaeff\Yii3Mcp\Utf8;

/**
 * Guards against a tool result burning an agent's context window — a bridged
 * GET against a real API, or a hand-written tool over a large table, can
 * return megabytes of JSON with no natural upper bound. A string result over
 * the limit is truncated with an explicit marker (still useful to the
 * agent); any other result (array, object — the shapes that become
 * `structuredContent`) is rejected outright instead, because a truncated
 * JSON payload is invalid JSON, not a smaller valid one. A string result
 * that is not valid UTF-8 (a bridged operation can return a foreign,
 * non-JSON upstream body as-is) is rejected with a `ToolCallException`
 * rather than reaching the SDK's envelope encoding and failing there
 * opaquely.
 *
 * @api
 */
final readonly class ResponseSizeLimitInterceptor implements ToolCallInterceptorInterface
{
    public function __construct(
        private int $maxBytes,
    ) {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException(sprintf('Tool result byte limit must be at least 1, %d given', $maxBytes));
        }
    }

    #[\Override]
    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        /** @var mixed $result */
        $result = $next();

        if (is_string($result)) {
            return $this->truncated($context, $result);
        }

        $this->assertWithinLimit($context, $result);

        return $result;
    }

    private function truncated(ToolCallContext $context, string $result): string
    {
        $size = strlen($result);
        $kept = $size <= $this->maxBytes ? $result : Utf8::cut($result, $this->maxBytes);

        // Utf8::cut only avoids SPLITTING a character; it does not repair a
        // string that was never valid UTF-8 (e.g. a bridged operation whose
        // non-JSON success body — see HttpOperationExecutor::execute()'s
        // fallback — is a legacy-encoded or binary upstream payload). Left
        // unchecked, this reaches the SDK's JSON_THROW_ON_ERROR envelope
        // encoding and throws an uncaught JsonException: an opaque internal
        // error instead of a proper tool-error envelope.
        if (preg_match('//u', $kept) !== 1) {
            throw new ToolCallException(sprintf(
                'Tool "%s" result is not valid UTF-8 (%d bytes)',
                $context->toolName,
                $size,
            ));
        }

        if ($size <= $this->maxBytes) {
            return $result;
        }

        // the kept size is reported from the cut itself, not from the limit:
        // Utf8::cut backs off up to three bytes rather than split a character
        return $kept . sprintf(' …[truncated, showing %d of %d bytes]', strlen($kept), $size);
    }

    private function assertWithinLimit(ToolCallContext $context, mixed $result): void
    {
        $size = strlen(json_encode($result, JSON_THROW_ON_ERROR));

        if ($size > $this->maxBytes) {
            throw new ToolCallException(sprintf(
                'Tool "%s" result of %d bytes exceeds the configured limit of %d bytes',
                $context->toolName,
                $size,
                $this->maxBytes,
            ));
        }
    }
}
