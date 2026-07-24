<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Interceptor;

use Mcp\Exception\PromptGetException;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Exception\ResourceReadException;
use Mcp\Exception\ToolCallException;
use Rasuvaeff\Yii3Mcp\Interceptor\CallOutcome;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Throwable;

#[Test]
#[Covers(CallOutcome::class)]
final class CallOutcomeTest
{
    #[DataProvider('rejectionsProvider')]
    public function clientVisibleRejectionsClassifyAsRejected(Throwable $exception): void
    {
        Assert::same(CallOutcome::fromThrowable($exception), CallOutcome::Rejected);
    }

    public static function rejectionsProvider(): iterable
    {
        yield 'tool call' => [new ToolCallException('rate limited')];
        yield 'prompt get' => [new PromptGetException('denied')];
        yield 'resource read' => [new ResourceReadException('denied')];
    }

    #[DataProvider('errorsProvider')]
    public function everythingElseClassifiesAsError(Throwable $exception): void
    {
        Assert::same(CallOutcome::fromThrowable($exception), CallOutcome::Error);
    }

    public static function errorsProvider(): iterable
    {
        yield 'unexpected failure' => [new RuntimeException('boom')];
        yield 'not-found is hiding, not rejecting' => [new PromptNotFoundException('secret-prompt')];
    }

    public function backedValuesAreTheBridgeVocabulary(): void
    {
        Assert::same(CallOutcome::Success->value, 'success');
        Assert::same(CallOutcome::Rejected->value, 'rejected');
        Assert::same(CallOutcome::Error->value, 'error');
    }
}
