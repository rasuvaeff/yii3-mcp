<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Interceptor;

use Mcp\Schema\Result\CallToolResult;

/**
 * Public extension point for the FORMATTED result of every successful
 * tools/call: add content next to what the tool returned — a
 * {@see \Mcp\Schema\Content\ResourceLink} per item of a search result, an
 * image, a note — without re-implementing result formatting.
 *
 * Decorators run in configured order after the whole interceptor chain
 * (budget, interceptors, the result cache, the size limit) and after the
 * result became a {@see CallToolResult}: text in the configured `result_json`
 * mode and `structuredContent` by the rules of the request's protocol
 * revision. Keep `structuredContent` as it is unless the tool's
 * `outputSchema` still describes the new value — clients validate it.
 *
 * - Decorators run on every answer, cache hits included: the result cache
 *   stores the raw handler result.
 * - Added content does not count against `limits.tool_result_bytes`, which
 *   measures the raw result inside the chain.
 * - A multi round-trip ask (`input_required`) is never decorated; the final
 *   round's result is.
 * - A {@see \Mcp\Exception\ToolCallException} never reaches a decorator: the
 *   SDK turns it into an error envelope after the handler. A handler that
 *   returns `CallToolResult::error()` itself does — check
 *   `$result->isError`.
 *
 * @api
 */
interface ToolResultDecoratorInterface
{
    public function decorate(CallToolResult $result, ToolCallContext $context): CallToolResult;
}
