<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Exception\ToolCallException;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;

final readonly class ShortCircuitInterceptor implements ToolCallInterceptorInterface
{
    public function __construct(
        private mixed $result = null,
        private ?string $rejectWith = null,
    ) {}

    #[\Override]
    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        if ($this->rejectWith !== null) {
            throw new ToolCallException($this->rejectWith);
        }

        return $this->result;
    }
}
