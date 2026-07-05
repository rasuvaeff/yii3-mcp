<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Yii3Mcp\Interceptor\ToolCallInterceptorInterface;

final class RecordingInterceptor implements ToolCallInterceptorInterface
{
    /** @var list<string> shared timeline: several interceptors may append here */
    public array $entries = [];

    public ?ToolCallContext $lastContext = null;

    public function __construct(
        private readonly string $name = 'interceptor',
        private readonly ?self $timeline = null,
    ) {}

    #[\Override]
    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        $log = $this->timeline ?? $this;
        $this->lastContext = $context;
        $log->entries[] = $this->name . ':before:' . $context->toolName;

        /** @var mixed $result */
        $result = $next();

        $log->entries[] = $this->name . ':after:' . $context->toolName;

        return $result;
    }
}
