<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Exception\ResourceReadException;
use Rasuvaeff\Yii3Mcp\Interceptor\ResourceReadContext;
use Rasuvaeff\Yii3Mcp\Interceptor\ResourceReadInterceptorInterface;

final class RecordingResourceInterceptor implements ResourceReadInterceptorInterface
{
    /** @var list<string> shared timeline: several interceptors may append here */
    public array $entries = [];

    public ?ResourceReadContext $lastContext = null;

    public function __construct(
        private readonly string $name = 'resource-interceptor',
        private readonly ?self $timeline = null,
        private readonly string $rejectWith = '',
    ) {}

    #[\Override]
    public function intercept(ResourceReadContext $context, callable $next): mixed
    {
        $log = $this->timeline ?? $this;
        $this->lastContext = $context;
        $log->entries[] = $this->name . ':before:' . $context->uri;

        if ($this->rejectWith !== '') {
            throw new ResourceReadException($this->rejectWith);
        }

        /** @var mixed $result */
        $result = $next();

        $log->entries[] = $this->name . ':after:' . $context->uri;

        return $result;
    }
}
