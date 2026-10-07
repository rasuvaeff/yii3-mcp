<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds a configured {@see OpenApiServerConfigurator} from what a consumer
 * actually has: a spec (file path, http(s) URL or a decoded document), a base
 * URL, PSR-18/PSR-17 services and the operation allow-list.
 *
 * This exists so the bridge is a PACKAGE feature rather than a Yii3-application
 * one. `SpecIndex` and `HttpOperationExecutor` are `@internal`, so a consumer
 * outside `Rasuvaeff\` cannot construct them without a Psalm `InternalClass`
 * error it has no legal way to silence; this factory constructs both itself
 * and hands back the `@api` configurator, which is all
 * {@see \Rasuvaeff\Yii3Mcp\McpServerFactory::create()} needs. Their
 * constructors stay free to move.
 *
 * `McpServerComponentResolver` calls this too, so the config-plugin path and a
 * standalone server assemble the bridge through the same code.
 *
 * @api
 */
final readonly class OpenApiBridgeFactory
{
    /**
     * @param array<string, mixed>|string $spec an http(s) URL, a local file path,
     *                                          or an already-decoded OpenAPI document
     * @param list<string> $operations allow-list of operationIds to expose
     * @param array<string, string> $headers operation-call headers, sent to $baseUrl only
     * @param array<string, string> $specHeaders spec-fetch headers, sent to a URL $spec only —
     *                                           a separate credential scope on purpose, so an API
     *                                           token never reaches a foreign spec host
     * @param ?CacheInterface $specCache PSR-16 cache for a URL $spec; ignored for a file or array
     * @param int $specCacheTtl TTL for that cache; 0 fetches on every build
     * @param array<string, string> $toolNames operationId => served tool name
     * @param list<string> $dryRunOperations operationIds that get a `dryRun` boolean argument
     * @param ?int $maxResponseBytes upstream body cap; null uses the package default
     * @param array<array-key, mixed> $multiSegmentPathParams path parameter name => how many
     *                                "/"-separated segments its values may carry
     * @param ?RequestHandlerInterface $inProcessHandler selects in-process execution: the application's
     *                                own handler receives the same request the HTTP executor would
     *                                send, without network I/O; null (default) keeps real HTTP calls
     * @param ?ServerRequestFactoryInterface $serverRequestFactory required together with $inProcessHandler
     * @param ?ExecutionRequestAttributesInterface $requestAttributes maps the resolved ExecutionIdentity
     *                                to request attributes on the nested request (in-process only)
     * @param ?InProcessScopeInterface $inProcessScope enter()/leave() hooks around the nested handle()
     *                                for resetting request-scoped state (in-process only)
     */
    public static function create(
        string|array $spec,
        string $baseUrl,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        array $operations,
        array $headers = [],
        array $specHeaders = [],
        ?CacheInterface $specCache = null,
        int $specCacheTtl = 0,
        bool $safeMethodsOnly = false,
        array $toolNames = [],
        ?OperationModifierInterface $modifier = null,
        array $dryRunOperations = [],
        ?ExecutionIdentityProviderInterface $identityProvider = null,
        ?DelegatedHeaderProviderInterface $delegatedHeaderProvider = null,
        ?int $maxResponseBytes = null,
        bool $opaqueErrors = false,
        array $multiSegmentPathParams = [],
        ?RequestHandlerInterface $inProcessHandler = null,
        ?ServerRequestFactoryInterface $serverRequestFactory = null,
        ?ExecutionRequestAttributesInterface $requestAttributes = null,
        ?InProcessScopeInterface $inProcessScope = null,
    ): OpenApiServerConfigurator {
        // psr15 mode is selected by the handler alone: everything else about
        // the call (URI, headers, body, caps, error mapping) is identical,
        // so the presence of the one PSR-15-only service is the mode
        if ($inProcessHandler instanceof RequestHandlerInterface && !$serverRequestFactory instanceof ServerRequestFactoryInterface) {
            throw new InvalidArgumentException('In-process execution requires a ServerRequestFactoryInterface');
        }

        $maxResponseBytes ??= OperationResponseDecoder::DEFAULT_MAX_RESPONSE_BYTES;

        $executor = $inProcessHandler instanceof RequestHandlerInterface
            ? new Psr15OperationExecutor(
                handler: $inProcessHandler,
                serverRequestFactory: $serverRequestFactory,
                streamFactory: $streamFactory,
                baseUrl: $baseUrl,
                defaultHeaders: $headers,
                identityProvider: $identityProvider,
                delegatedHeaderProvider: $delegatedHeaderProvider,
                requestAttributes: $requestAttributes,
                inProcessScope: $inProcessScope,
                maxResponseBytes: $maxResponseBytes,
                opaqueErrors: $opaqueErrors,
                multiSegmentPathParams: $multiSegmentPathParams,
            )
            : new HttpOperationExecutor(
                httpClient: $httpClient,
                requestFactory: $requestFactory,
                streamFactory: $streamFactory,
                baseUrl: $baseUrl,
                defaultHeaders: $headers,
                identityProvider: $identityProvider,
                delegatedHeaderProvider: $delegatedHeaderProvider,
                maxResponseBytes: $maxResponseBytes,
                opaqueErrors: $opaqueErrors,
                multiSegmentPathParams: $multiSegmentPathParams,
            );

        return new OpenApiServerConfigurator(
            spec: self::index($spec, $httpClient, $requestFactory, $specHeaders, $specCache, $specCacheTtl),
            executor: $executor,
            operations: $operations,
            safeMethodsOnly: $safeMethodsOnly,
            toolNames: $toolNames,
            modifier: $modifier,
            dryRunOperations: $dryRunOperations,
        );
    }

    /**
     * @param array<string, mixed>|string $spec
     * @param array<string, string> $specHeaders
     */
    private static function index(
        string|array $spec,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        array $specHeaders,
        ?CacheInterface $specCache,
        int $specCacheTtl,
    ): SpecIndex {
        if (is_array($spec)) {
            return new SpecIndex($spec);
        }

        if (!str_starts_with($spec, 'http://') && !str_starts_with($spec, 'https://')) {
            return SpecIndex::fromFile($spec);
        }

        return (new SpecLoader(
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            headers: $specHeaders,
            cache: $specCache,
            cacheTtl: $specCacheTtl,
        ))->fromUrl($spec);
    }
}
