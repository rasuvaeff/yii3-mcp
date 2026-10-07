<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Executes a bridged operation as a real HTTP call against the upstream
 * REST API — the request passes the application's full middleware stack
 * (validation, rate limiting, auth), unlike direct handler invocation.
 *
 * The response body is read INCREMENTALLY up to `$maxResponseBytes` and the
 * call fails before a byte over the cap is buffered — an advertised size
 * (Content-Length/stream size) over the cap is rejected without reading at
 * all. `ResponseSizeLimitInterceptor` bounds what reaches the agent's
 * context window, but it runs after the executor returns; this cap is what
 * protects the worker's memory from an unbounded upstream body.
 *
 * Request assembly and response decoding live in {@see OperationRequestBuilder}
 * and {@see OperationResponseDecoder}, shared with
 * {@see Psr15OperationExecutor} — the transports cannot drift apart.
 *
 * @internal
 */
final readonly class HttpOperationExecutor implements OperationExecutorInterface
{
    private OperationRequestBuilder $builder;

    private OperationResponseDecoder $decoder;

    /**
     * @param array<string, string> $defaultHeaders e.g. ['Authorization' => 'Bearer …']
     * @param int $maxResponseBytes upper bound on the upstream response body this executor will buffer
     * @param bool $opaqueErrors suppress the upstream error-body excerpt in failures — for
     *                           service-token deployments where the upstream's error
     *                           details are not the MCP caller's to see
     * @param array<array-key, mixed> $multiSegmentPathParams path parameter name => how many
     *                           "/"-separated segments its values may carry. Absent (the
     *                           default for every parameter) means one segment, i.e. no
     *                           separator at all — see {@see OperationRequestBuilder::plan()}.
     *                           Values are validated by the builder rather than typed:
     *                           this arrives from params, so a string "1" must fail loudly
     *                           instead of enabling the multi-segment path with a limit no
     *                           comparison treats as one
     */
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        string $baseUrl,
        array $defaultHeaders = [],
        ?ExecutionIdentityProviderInterface $identityProvider = null,
        ?DelegatedHeaderProviderInterface $delegatedHeaderProvider = null,
        int $maxResponseBytes = OperationResponseDecoder::DEFAULT_MAX_RESPONSE_BYTES,
        bool $opaqueErrors = false,
        array $multiSegmentPathParams = [],
    ) {
        $this->builder = new OperationRequestBuilder(
            baseUrl: $baseUrl,
            defaultHeaders: $defaultHeaders,
            identityProvider: $identityProvider,
            delegatedHeaderProvider: $delegatedHeaderProvider,
            multiSegmentPathParams: $multiSegmentPathParams,
        );
        $this->decoder = new OperationResponseDecoder(
            maxResponseBytes: $maxResponseBytes,
            opaqueErrors: $opaqueErrors,
        );
    }

    /**
     * @param array<string, mixed> $arguments tool arguments keyed by parameter name
     * @param ?string $toolName the name this operation is SERVED under (after
     *                          `tool_names` and the operation modifier); null
     *                          falls back to the operationId, which is correct
     *                          only when the operation was never renamed
     */
    #[\Override]
    public function execute(Operation $operation, array $arguments, bool $dryRunnable = false, ?string $toolName = null): mixed
    {
        // everything the CALLER reads names the tool it actually called: a
        // renamed operation's operationId is exactly what the rename hid
        // from the client, so quoting it back gives an agent a
        // plausible-looking identifier that is in no tool list it has
        $served = $toolName ?? $operation->operationId;

        // a malformed flag must not silently fall through to a REAL call —
        // that is the dangerous direction for a write operation the caller
        // intended to preview. The SDK's schema validation rejects
        // non-boolean values first; this guard keeps the failure mode safe
        // even when the executor is reached directly
        $this->builder->assertDryRunFlag($arguments, $dryRunnable, $served);

        if ($dryRunnable && ($arguments[InputSchemaBuilder::DRY_RUN_ARGUMENT] ?? false) === true) {
            return $this->builder->dryRunPreview($operation, $arguments, $served);
        }

        $plan = $this->builder->plan($operation, $arguments, $served);

        $request = $this->requestFactory->createRequest($plan->method, $plan->url);

        foreach ($plan->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($plan->body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($plan->body));
        }

        return $this->decoder->decode($this->httpClient->sendRequest($request), $served);
    }
}
