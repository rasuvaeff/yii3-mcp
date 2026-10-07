<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Executes a bridged operation against the application's OWN request handler
 * in-process: the nested call carries the same method, URI, query, JSON body
 * and headers {@see HttpOperationExecutor} would send, but never touches the
 * network — one FPM worker instead of two, no loopback TLS/DNS, no latency.
 *
 * The handler is the application's, so re-entrancy is its contract: the
 * outer MCP request's scoped state (current route, current user) is still
 * set when the nested request runs. Isolate it by configuring an
 * {@see InProcessScopeInterface}, whose enter()/leave() the executor calls
 * around every nested handle() — leave() runs even when the handler throws.
 *
 * Delegated mode keeps working: the delegated headers arrive as headers, and
 * a configured {@see ExecutionRequestAttributesInterface} additionally maps
 * the resolved {@see ExecutionIdentity} to request attributes, so the nested
 * stack can authenticate the user from either.
 *
 * @internal
 */
final readonly class Psr15OperationExecutor implements OperationExecutorInterface
{
    private OperationRequestBuilder $builder;

    private OperationResponseDecoder $decoder;

    /**
     * @param array<string, string> $defaultHeaders operation-call headers, e.g. the API's service token
     * @param ?ExecutionRequestAttributesInterface $requestAttributes maps the resolved identity
     *                                                to request attributes on the nested request
     * @param ?InProcessScopeInterface $inProcessScope enter()/leave() hooks around the nested handle()
     * @param int $maxResponseBytes upper bound on the nested response body this executor will buffer
     * @param bool $opaqueErrors suppress the error-body excerpt in failures
     * @param array<array-key, mixed> $multiSegmentPathParams path parameter name => how many
     *                           "/"-separated segments its values may carry
     */
    public function __construct(
        private RequestHandlerInterface $handler,
        private ServerRequestFactoryInterface $serverRequestFactory,
        private StreamFactoryInterface $streamFactory,
        string $baseUrl,
        array $defaultHeaders = [],
        private ?ExecutionIdentityProviderInterface $identityProvider = null,
        private ?DelegatedHeaderProviderInterface $delegatedHeaderProvider = null,
        private ?ExecutionRequestAttributesInterface $requestAttributes = null,
        private ?InProcessScopeInterface $inProcessScope = null,
        int $maxResponseBytes = OperationResponseDecoder::DEFAULT_MAX_RESPONSE_BYTES,
        bool $opaqueErrors = false,
        array $multiSegmentPathParams = [],
    ) {
        $this->builder = new OperationRequestBuilder(
            baseUrl: $baseUrl,
            defaultHeaders: $defaultHeaders,
            identityProvider: $this->identityProvider,
            delegatedHeaderProvider: $this->delegatedHeaderProvider,
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

        $this->builder->assertDryRunFlag($arguments, $dryRunnable, $served);

        if ($dryRunnable && ($arguments[InputSchemaBuilder::DRY_RUN_ARGUMENT] ?? false) === true) {
            return $this->builder->dryRunPreview($operation, $arguments, $served);
        }

        $plan = $this->builder->plan($operation, $arguments, $served);

        $request = $this->serverRequestFactory->createServerRequest($plan->method, $plan->url);

        foreach ($plan->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($plan->body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($plan->body));
        }

        // attributes derive from the SAME identity resolution the delegated
        // headers did (the builder resolves it once per call); a mapper
        // without an identity has nothing to map — static-header mode
        if ($this->requestAttributes instanceof ExecutionRequestAttributesInterface && $plan->identity instanceof ExecutionIdentity) {
            /** @var mixed $value */
            foreach ($this->requestAttributes->attributes($plan->identity) as $name => $value) {
                $request = $request->withAttribute($name, $value);
            }
        }

        $this->inProcessScope?->enter();

        try {
            $response = $this->handler->handle($request);
        } finally {
            $this->inProcessScope?->leave();
        }

        return $this->decoder->decode($response, $served);
    }
}
