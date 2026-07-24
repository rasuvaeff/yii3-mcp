<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Support;

use Mcp\Exception\PromptGetException;
use Rasuvaeff\Yii3Mcp\Interceptor\PromptGetContext;
use Rasuvaeff\Yii3Mcp\Interceptor\PromptGetInterceptorInterface;

final class RecordingPromptInterceptor implements PromptGetInterceptorInterface
{
    /** @var list<string> shared timeline: several interceptors may append here */
    public array $entries = [];

    public ?PromptGetContext $lastContext = null;

    public function __construct(
        private readonly string $name = 'prompt-interceptor',
        private readonly ?self $timeline = null,
        private readonly string $rejectWith = '',
    ) {}

    #[\Override]
    public function intercept(PromptGetContext $context, callable $next): mixed
    {
        $log = $this->timeline ?? $this;
        $this->lastContext = $context;
        $log->entries[] = $this->name . ':before:' . $context->promptName;

        if ($this->rejectWith !== '') {
            throw new PromptGetException($this->rejectWith);
        }

        /** @var mixed $result */
        $result = $next();

        $log->entries[] = $this->name . ':after:' . $context->promptName;

        return $result;
    }
}
