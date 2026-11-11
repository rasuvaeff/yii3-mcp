<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp;

use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Capability\Registry\ToolReference;

/**
 * Builds a ready {@see CallToolResult} exactly as the SDK's CallToolHandler
 * would for a raw tool result, with the JSON text either compact (the SDK's
 * own encoding since 0.10) or pretty-printed.
 *
 * The package needs its own copy for two reasons: the `pretty` result_json
 * mode (the SDK stopped pretty-printing in 0.10), and result decorators,
 * which need a formatted result BEFORE the SDK's CallToolHandler sees it —
 * the handler skips BOTH formatting and structured-content extraction for a
 * ready CallToolResult.
 *
 * Mirrors, branch for branch: CallToolHandler::handle() (a null or scalar
 * structuredContent is sent as its JSON text, SEP-2106) and the SDK's
 * ToolResultFormatter::format() (Content pass-through, mixed-array per-item
 * formatting, scalar/null/bool sentinels). structuredContent itself is NOT
 * mirrored: it is delegated to ToolReference::extractStructuredContent(),
 * whose rules depend on the protocol revision. On an SDK pin bump, re-diff
 * both SDK methods.
 *
 * @internal
 */
final readonly class ToolResultTextFormatter
{
    /**
     * The SDK's own flags for tool results.
     */
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR
        | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param mixed $toolExecutionResult the raw value returned by the tool's PHP method
     * @param bool $pretty indent the JSON text (structured content is unaffected)
     */
    public static function format(
        mixed $toolExecutionResult,
        ToolReference $reference,
        ProtocolVersion $protocolVersion,
        bool $pretty = false,
    ): CallToolResult {
        $flags = $pretty ? self::JSON_FLAGS | JSON_PRETTY_PRINT : self::JSON_FLAGS;
        /** @var mixed $structuredContent */
        $structuredContent = $reference->extractStructuredContent($toolExecutionResult, $protocolVersion);

        // SEP-2106: a primitive structuredContent carries its serialized JSON in content
        $content = $structuredContent === null || is_scalar($structuredContent)
            ? [new TextContent(json_encode($structuredContent, $flags))]
            : self::content($toolExecutionResult, $flags);

        return new CallToolResult($content, structuredContent: $structuredContent);
    }

    /**
     * @return Content[]
     */
    private static function content(mixed $toolExecutionResult, int $flags): array
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
                        $result = array_merge($result, self::content($item, $flags));
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

        return [new TextContent(json_encode($toolExecutionResult, $flags))];
    }
}
