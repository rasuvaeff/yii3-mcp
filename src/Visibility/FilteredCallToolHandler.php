<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Visibility;

use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * tools/call with per-session visibility — registered by McpServerFactory
 * ahead of the SDK handler (custom request handlers win), which it then
 * delegates to for every visible or unknown tool.
 *
 * The visibility check inside the reference handler runs too late to hide
 * a tool: the SDK's CallToolHandler first validates the arguments against
 * the tool's input schema (an invalid call reveals the schema) and only
 * maps a not-found raised by its own registry lookup to `-32602`. Answering
 * here, before either, makes a hidden tool byte-for-byte identical to a
 * missing one — the message comes from the SDK's own exception.
 *
 * @implements RequestHandlerInterface<CallToolResult|InputRequiredResult>
 *
 * @internal wired by {@see \Rasuvaeff\Yii3Mcp\McpServerFactory}
 */
final readonly class FilteredCallToolHandler implements RequestHandlerInterface
{
    /**
     * @param RequestHandlerInterface<CallToolResult|InputRequiredResult> $inner the SDK handler serving visible tools
     */
    public function __construct(
        private RegistryInterface $registry,
        private RequestHandlerInterface $inner,
        private ToolVisibilityInterface $visibility,
    ) {}

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    /**
     * @return Response<CallToolResult|InputRequiredResult>|Error
     */
    #[\Override]
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CallToolRequest);

        // an unknown tool is left to the inner handler, which produces the
        // canonical error — "not found" is phrased in one place only
        if ($this->registry->hasTool($request->name)
            && !$this->visibility->isVisible($this->registry->getTool($request->name)->tool, $session)
        ) {
            return Error::forInvalidParams((new ToolNotFoundException($request->name))->getMessage(), $request->getId());
        }

        return $this->inner->handle($request, $session);
    }
}
