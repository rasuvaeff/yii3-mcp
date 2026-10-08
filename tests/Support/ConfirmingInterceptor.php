<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;

/**
 * Asks the user before letting a call through — the shape an application
 * uses to confirm a costly operation it does not own the handler of (an
 * OpenAPI-bridged tool). Records the trace context it saw.
 */
final class ConfirmingInterceptor implements ToolCallInterceptorInterface
{
    /** @var list<array<string, string>> */
    public array $traces = [];

    #[\Override]
    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        $request = $context->requestContext;

        if (!$request instanceof \Mcp\Server\RequestContext) {
            throw new ToolCallException('No request scope');
        }

        $this->traces[] = $request->getTraceContext();

        $answer = $request->getClientGateway()->elicit(
            'Spend one contact reveal?',
            new ElicitationSchema(['confirm' => new BooleanSchemaDefinition(title: 'Confirm')], ['confirm']),
            key: 'confirm-reveal',
        );

        if (!$answer->isAccepted() || ($answer->content['confirm'] ?? false) !== true) {
            return 'not revealed';
        }

        return $next();
    }
}
