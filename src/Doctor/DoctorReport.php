<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\Doctor;

/**
 * Immutable outcome of a full {@see McpDoctor} run: the ordered check results
 * plus the derived health verdict and exit code.
 *
 * @api
 */
final readonly class DoctorReport
{
    /**
     * @param list<CheckResult> $checks in diagnosis order (root causes first)
     */
    public function __construct(
        public array $checks,
    ) {}

    public function healthy(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->status === CheckStatus::Fail) {
                return false;
            }
        }

        return true;
    }

    /**
     * 0 when healthy; otherwise the {@see CheckCategory::exitCode()} of the
     * FIRST failing check — checks run in diagnosis order, so the first
     * failure is the root cause (a broken config also breaks the server
     * build, but the config error is what needs fixing).
     */
    public function exitCode(): int
    {
        foreach ($this->checks as $check) {
            if ($check->status === CheckStatus::Fail) {
                return $check->category->exitCode();
            }
        }

        return 0;
    }

    /**
     * @return array{healthy: bool, exitCode: int, checks: list<array{name: string, category: string, status: string, details: string}>}
     */
    public function toArray(): array
    {
        return [
            'healthy' => $this->healthy(),
            'exitCode' => $this->exitCode(),
            'checks' => array_map(static fn(CheckResult $check): array => $check->toArray(), $this->checks),
        ];
    }
}
