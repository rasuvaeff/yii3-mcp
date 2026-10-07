<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\TextContent;
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
 * Every branch must stay a mirror of the SDK formatter (Content pass-through,
 * mixed-array per-item formatting, scalar/null/bool special cases) — on an
 * SDK pin bump, re-diff against ToolResultFormatter::format() and
 * ToolReference::extractStructuredContent().
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
    public static function format(mixed $toolExecutionResult): CallToolResult
    {
        return new CallToolResult(
            self::content($toolExecutionResult),
            structuredContent: self::structuredContent($toolExecutionResult),
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

    /**
     * Mirrors ToolReference::extractStructuredContent(): an array is served
     * as-is (the SDK does this unconditionally, outputSchema or not), an
     * object is round-tripped through JSON, everything else has none.
     *
     *
     * @return array<string, mixed>|null
     */
    private static function structuredContent(mixed $toolExecutionResult): ?array
    {
        if (is_array($toolExecutionResult)) {
            /** @var array<string, mixed> $toolExecutionResult */
            return $toolExecutionResult;
        }

        if (is_object($toolExecutionResult) && !($toolExecutionResult instanceof Content)) {
            /** @var array<string, mixed> */
            return json_decode(
                json_encode($toolExecutionResult, self::JSON_FLAGS),
                associative: true,
                depth: 512,
                flags: JSON_THROW_ON_ERROR,
            );
        }

        return null;
    }
}
