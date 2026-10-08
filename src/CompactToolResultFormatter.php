<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Result\CallToolResult;

/**
 * Builds a ready {@see CallToolResult} with compact JSON text — the same
 * branching as the SDK's ToolResultFormatter plus
 * ToolReference::extractStructuredContent(), minus JSON_PRETTY_PRINT.
 *
 * The SDK's CallToolHandler skips BOTH formatting and structured-content
 * extraction when the reference handler already returns a CallToolResult,
 * which is exactly why this exists: pretty-printed tool results cost an
 * agent ~3x context tokens for an array payload, and the workaround of
 * returning a pre-encoded string loses structuredContent.
 *
 * The text branches must stay a mirror of the SDK formatter (Content
 * pass-through, mixed-array per-item formatting, scalar/null/bool special
 * cases) — on an SDK pin bump, re-diff against ToolResultFormatter::format().
 * structuredContent is NOT mirrored: it is delegated to the SDK's own
 * ToolReference::extractStructuredContent(), whose rules depend on the
 * protocol revision (a list is structured content only from 2026-07-28 on).
 *
 * @internal
 */
final readonly class CompactToolResultFormatter
{
    /**
     * The SDK's own flags for tool results, minus JSON_PRETTY_PRINT.
     */
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR
        | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param mixed $toolExecutionResult the raw value returned by the tool's PHP method
     */
    public static function format(
        mixed $toolExecutionResult,
        ToolReference $reference,
        ProtocolVersion $protocolVersion,
    ): CallToolResult {
        return new CallToolResult(
            self::content($toolExecutionResult),
            structuredContent: $reference->extractStructuredContent($toolExecutionResult, $protocolVersion),
        );
    }

    /**
     * @return Content[]
     */
    private static function content(mixed $toolExecutionResult): array
    {
        if ($toolExecutionResult instanceof Content) {
            return [$toolExecutionResult];
        }

        if (is_array($toolExecutionResult)) {
            if ($toolExecutionResult === []) {
                return [new TextContent('[]')];
            }

            $allAreContent = true;
            $hasContent = false;

            /** @var mixed $item */
            foreach ($toolExecutionResult as $item) {
                if ($item instanceof Content) {
                    $hasContent = true;
                } else {
                    $allAreContent = false;
                }
            }

            if ($allAreContent && $hasContent) {
                /** @var array<array-key, Content> $toolExecutionResult — every element proved instanceof Content above */
                return $toolExecutionResult;
            }

            if ($hasContent) {
                $result = [];

                /** @var mixed $item */
                foreach ($toolExecutionResult as $item) {
                    if ($item instanceof Content) {
                        $result[] = $item;
                    } else {
                        $result = array_merge($result, self::content($item));
                    }
                }

                return $result;
            }
        }

        if (null === $toolExecutionResult) {
            return [new TextContent('(null)')];
        }

        if (is_bool($toolExecutionResult)) {
            return [new TextContent($toolExecutionResult ? 'true' : 'false')];
        }

        if (is_scalar($toolExecutionResult)) {
            return [new TextContent($toolExecutionResult)];
        }

        return [new TextContent(json_encode($toolExecutionResult, self::JSON_FLAGS))];
    }
}
