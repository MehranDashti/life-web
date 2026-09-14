<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use Tests\TestCase;
use App\Enums\ReportPeriod;
use App\Enums\ReportStatus;
use App\Models\Report\Report;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Repositories\Contracts\Report\ReportRepositoryInterface;

class ReportRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ReportRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ReportRepositoryInterface::class);
    }

    public function test_it_yields_only_active_due_reports_of_the_requested_period(): void
    {
        $due = Report::factory()->due()->create();
        Report::factory()->due()->paused()->create();
        Report::factory()->notYetDue()->create();
        Report::factory()->weekly()->due()->create();

        $seen = $this->collectDue(ReportPeriod::Daily);

        $this->assertSame([$due->id], $seen);
    }

    public function test_it_returns_the_number_of_reports_handed_to_the_callback(): void
    {
        Report::factory()->due()->count(5)->create();

        $this->assertSame(5, $this->repository->eachDueForDispatch(
            ReportPeriod::Daily,
            Carbon::now(),
            static function (): void {},
        ));
    }

    /**
     * A large backlog must never load into memory at once — the dispatcher's cost
     * has to track the work, not the size of the table.
     */
    public function test_it_chunks_rather_than_loading_every_row(): void
    {
        Report::factory()->due()->count(7)->create();

        $chunkSizes = [];

        $this->repository->eachDueForDispatch(
            ReportPeriod::Daily,
            Carbon::now(),
            function ($reports) use (&$chunkSizes): void {
                $chunkSizes[] = $reports->count();
            },
            chunkSize: 3,
        );

        $this->assertSame([3, 3, 1], $chunkSizes);
    }

    public function test_a_report_with_no_scheduled_run_is_never_due(): void
    {
        Report::factory()->create(['next_run_at' => null]);

        $this->assertSame([], $this->collectDue(ReportPeriod::Daily));
    }

    public function test_marking_a_run_succeeded_advances_the_schedule_and_clears_failures(): void
    {
        $report = Report::factory()->due()->create(['consecutive_failures' => 3]);

        $windowEnd = ReportPeriod::Daily->windowEnd(Carbon::now());
        $this->repository->markRunSucceeded($report, $windowEnd);

        $fresh = $report->fresh();

        $this->assertSame(0, $fresh->consecutive_failures);
        $this->assertSame($windowEnd->toDateTimeString(), $fresh->last_run_at?->toDateTimeString());
        $this->assertTrue($fresh->next_run_at?->isFuture(), 'The report must stop being due.');
    }

    /**
     * Not advancing on failure is what makes the window get retried rather than
     * silently skipped.
     */
    public function test_marking_a_run_failed_increments_the_streak_without_advancing(): void
    {
        $report = Report::factory()->due()->create();
        $scheduledFor = $report->next_run_at;

        $this->assertSame(1, $this->repository->markRunFailed($report));
        $this->assertSame(2, $this->repository->markRunFailed($report->fresh()));

        $this->assertTrue($scheduledFor?->equalTo($report->fresh()->next_run_at));
    }

    public function test_pausing_a_report_takes_it_out_of_dispatch(): void
    {
        $report = Report::factory()->due()->create();

        $this->repository->pause($report);

        $this->assertSame(ReportStatus::Paused, $report->fresh()->status);
        $this->assertSame([], $this->collectDue(ReportPeriod::Daily));
    }

    /**
     * @return array<int, string>
     */
    private function collectDue(ReportPeriod $period): array
    {
        $seen = [];

        $this->repository->eachDueForDispatch($period, Carbon::now(), function ($reports) use (&$seen): void {
            foreach ($reports as $report) {
                $seen[] = $report->id;
            }
        });

        return $seen;
    }
}
