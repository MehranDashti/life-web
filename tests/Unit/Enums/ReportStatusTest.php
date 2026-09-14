<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use Tests\TestCase;
use App\Enums\ReportStatus;
use App\Enums\ReportRunStatus;

class ReportStatusTest extends TestCase
{
    public function test_report_status_exposes_every_case_for_validation(): void
    {
        $this->assertSame(['active', 'paused'], ReportStatus::values());
    }

    public function test_report_run_status_exposes_every_case(): void
    {
        $this->assertSame(['pending', 'running', 'succeeded', 'failed'], ReportRunStatus::values());
    }

    /**
     * A terminal run is one the dispatcher will never revisit on its own.
     */
    public function test_only_succeeded_and_failed_are_terminal(): void
    {
        $this->assertTrue(ReportRunStatus::Succeeded->isTerminal());
        $this->assertTrue(ReportRunStatus::Failed->isTerminal());
        $this->assertFalse(ReportRunStatus::Pending->isTerminal());
        $this->assertFalse(ReportRunStatus::Running->isTerminal());
    }

    /**
     * The backing values are persisted in a string column and appear in API
     * responses, so renaming one is a breaking change to both.
     */
    public function test_backing_values_are_stable(): void
    {
        $this->assertSame('active', ReportStatus::Active->value);
        $this->assertSame('paused', ReportStatus::Paused->value);
        $this->assertSame('succeeded', ReportRunStatus::Succeeded->value);
        $this->assertSame('failed', ReportRunStatus::Failed->value);
    }

    /**
     * values() feeds validation rules, so it drifting out of step with the cases is
     * the real risk: a case that is not in values() can be persisted by the
     * application but rejected by its own validator.
     */
    public function test_every_case_round_trips_through_its_backing_value(): void
    {
        foreach (ReportStatus::values() as $value) {
            $this->assertInstanceOf(ReportStatus::class, ReportStatus::tryFrom($value));
        }

        foreach (ReportRunStatus::values() as $value) {
            $this->assertInstanceOf(ReportRunStatus::class, ReportRunStatus::tryFrom($value));
        }

        $this->assertCount(count(ReportStatus::cases()), ReportStatus::values());
        $this->assertCount(count(ReportRunStatus::cases()), ReportRunStatus::values());
    }
}
