<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

/**
 * A bridged operation call reduced to transport-neutral parts: everything
 * {@see HttpOperationExecutor} and {@see Psr15OperationExecutor} need to
 * produce their request, produced once by {@see OperationRequestBuilder} so
 * the two transports cannot drift apart.
 *
 * @internal
 */
final readonly class PlannedOperationRequest
{
    /**
     * @param string $method HTTP method of the operation
     * @param string $url base URL + substituted path + query string
     * @param array<string, string> $headers the complete header set: configured defaults,
     *                                       delegated headers (when an identity is configured)
     *                                       and the bridge's own Accept / Content-Type entries
     * @param ?string $body JSON-encoded request body, or null when no body argument was passed
     *                      (a body argument explicitly null still encodes as the literal "null")
     * @param ?ExecutionIdentity $identity the identity delegated headers were minted from and
     *                                     request attributes are derived from; null in
     *                                     static-header mode
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?string $body,
        public ?ExecutionIdentity $identity = null,
    ) {}
}
