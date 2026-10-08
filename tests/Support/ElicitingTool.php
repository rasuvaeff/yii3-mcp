<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Server\RequestContext;

/**
 * Tools that ask the user mid-call. On the stateless era each ask ends the
 * request with `input_required` and the handler runs again from the top once
 * the answer is re-sent — `$runs` counts those entries.
 */
final class ElicitingTool
{
    public int $runs = 0;

    #[McpTool(name: 'order.delete')]
    public function delete(string $orderId, RequestContext $context): string
    {
        $this->runs++;

        $answer = $context->getClientGateway()->elicit(
            'Delete order ' . $orderId . '?',
            new ElicitationSchema(['confirm' => new BooleanSchemaDefinition(title: 'Confirm')], ['confirm']),
            key: 'confirm',
        );

        return $answer->isAccepted() && ($answer->content['confirm'] ?? false) === true
            ? 'deleted ' . $orderId
            : 'kept ' . $orderId;
    }

    /**
     * Two asks: the first answer has to survive into the second round, which
     * is what the signed requestState carries.
     */
    #[McpTool(name: 'order.rename')]
    public function rename(string $orderId, RequestContext $context): string
    {
        $this->runs++;
        $gateway = $context->getClientGateway();

        $name = $gateway->elicit(
            'New name?',
            new ElicitationSchema(['name' => new StringSchemaDefinition(title: 'Name')], ['name']),
            key: 'name',
        );
        $confirm = $gateway->elicit(
            'Sure?',
            new ElicitationSchema(['confirm' => new BooleanSchemaDefinition(title: 'Confirm')], ['confirm']),
            key: 'confirm',
        );

        return $confirm->isAccepted()
            ? $orderId . ' renamed to ' . ($name->content['name'] ?? '')
            : 'kept ' . $orderId;
    }
}
