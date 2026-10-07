<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

/**
 * Isolates request-scoped state around a nested in-process call
 * ({@see Psr15OperationExecutor}).
 *
 * When the bridge executes the application's own request handler, the outer
 * MCP request's scoped state — current route, current user, URI already set
 * on the shared request — is still in place, and a naive re-entry fails
 * (Yii3's own `Can not set URI since it was already set`) or, worse, serves
 * the nested call with the outer request's identity. Implementations reset
 * what their stack globalizes in enter() and restore it in leave().
 *
 * The executor calls enter() immediately before the nested handle() and
 * leave() in a finally block — leave() runs even when the handler throws,
 * and only ever after enter() of the same invocation has run.
 *
 * Resolved through the DI container; stateless implementations may be shared.
 *
 * @api
 */
interface InProcessScopeInterface
{
    /**
     * Runs immediately before the nested request enters the application's
     * handler. Reset/swap every piece of request-scoped state here.
     */
    public function enter(): void;

    /**
     * Runs after the nested request left the application's handler — also on
     * failure. Restore what enter() replaced.
     */
    public function leave(): void;
}
