<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Tests\Doctor;

use Rasuvaeff\Yii3Mcp\Doctor\CheckCategory;
use Rasuvaeff\Yii3Mcp\Doctor\CheckResult;
use Rasuvaeff\Yii3Mcp\Doctor\CheckStatus;
use Rasuvaeff\Yii3Mcp\Doctor\DoctorReport;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DoctorReport::class)]
final class DoctorReportTest
{
    public function healthyWhenNoCheckFails(): void
    {
        $report = new DoctorReport([
            $this->check('a', CheckCategory::Config, CheckStatus::Pass),
            $this->check('b', CheckCategory::Upstream, CheckStatus::Skip),
        ]);

        Assert::true($report->healthy());
        Assert::same($report->exitCode(), 0);
    }

    public function skippedChecksDoNotFailTheReport(): void
    {
        $report = new DoctorReport([
            $this->check('a', CheckCategory::Upstream, CheckStatus::Skip),
        ]);

        Assert::true($report->healthy());
    }

    #[DataProvider('categoryExitCodeProvider')]
    public function exitCodeReflectsTheFailingCategory(CheckCategory $category, int $expected): void
    {
        $report = new DoctorReport([
            $this->check('a', CheckCategory::Config, CheckStatus::Pass),
            $this->check('b', $category, CheckStatus::Fail),
        ]);

        Assert::false($report->healthy());
        Assert::same($report->exitCode(), $expected);
    }

    public static function categoryExitCodeProvider(): iterable
    {
        yield 'config' => [CheckCategory::Config, 2];
        yield 'storage' => [CheckCategory::Storage, 3];
        yield 'upstream' => [CheckCategory::Upstream, 4];
    }

    public function firstFailingCheckDeterminesTheExitCode(): void
    {
        // Checks run in diagnosis order: the first failure is the root cause
        // even when a later check fails in a different category.
        $report = new DoctorReport([
            $this->check('secret', CheckCategory::Config, CheckStatus::Pass),
            $this->check('store', CheckCategory::Storage, CheckStatus::Fail),
            $this->check('build', CheckCategory::Config, CheckStatus::Fail),
        ]);

        Assert::same($report->exitCode(), 3);
    }

    public function exposesTheMachineReadableArray(): void
    {
        $report = new DoctorReport([
            $this->check('secret', CheckCategory::Config, CheckStatus::Fail),
        ]);

        Assert::same($report->toArray(), [
            'healthy' => false,
            'exitCode' => 2,
            'checks' => [
                ['name' => 'secret', 'category' => 'config', 'status' => 'fail', 'details' => 'details'],
            ],
        ]);
    }

    private function check(string $name, CheckCategory $category, CheckStatus $status): CheckResult
    {
        return new CheckResult(name: $name, category: $category, status: $status, details: 'details');
    }
}
