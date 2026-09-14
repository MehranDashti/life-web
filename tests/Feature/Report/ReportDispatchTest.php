<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use App\Models\Report\Report;
use Illuminate\Support\Carbon;
use App\Jobs\GenerateReportJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReportDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('reports');
        $this->fakeSearch();
    }

    public function test_it_enqueues_one_job_per_due_report(): void
    {
        Queue::fake();
        Report::factory()->due()->count(3)->create();

        $this->artisan('reports:dispatch daily')->assertSuccessful();

        Queue::assertPushed(GenerateReportJob::class, 3);
    }

    public function test_paused_reports_are_not_dispatched(): void
    {
        Queue::fake();
        Report::factory()->due()->paused()->create();

        $this->artisan('reports:dispatch daily')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_reports_not_yet_due_are_not_dispatched(): void
    {
        Queue::fake();
        Report::factory()->notYetDue()->create();

        $this->artisan('reports:dispatch daily')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_weekly_dispatch_ignores_daily_reports(): void
    {
        Queue::fake();
        Report::factory()->due()->create();
        Report::factory()->weekly()->due()->create();

        $this->artisan('reports:dispatch weekly')->assertSuccessful();

        Queue::assertPushed(GenerateReportJob::class, 1);
    }

    public function test_an_unknown_period_is_rejected(): void
    {
        $this->artisan('reports:dispatch hourly')->assertFailed();
    }

    public function test_jobs_land_on_the_dedicated_reports_queue(): void
    {
        Queue::fake();
        Report::factory()->due()->create();

        $this->artisan('reports:dispatch daily')->assertSuccessful();

        Queue::assertPushed(
            GenerateReportJob::class,
            static fn (GenerateReportJob $job): bool => $job->queue === 'reports',
        );
    }

    public function test_a_successful_run_advances_the_schedule(): void
    {
        $report = Report::factory()->due()->create();
        $before = $report->next_run_at;

        $this->artisan('reports:dispatch daily --sync')->assertSuccessful();

        $report->refresh();

        $this->assertTrue($report->next_run_at?->gt($before));
        $this->assertNotNull($report->last_run_at);
        $this->assertSame(0, $report->consecutive_failures);
    }

    /**
     * Without this, a report whose window has already been produced stays
     * permanently due and every tick re-dispatches a job that does nothing.
     */
    public function test_a_second_dispatch_of_a_completed_window_still_advances_the_schedule(): void
    {
        $report = Report::factory()->due()->create();

        $this->artisan('reports:dispatch daily --sync')->assertSuccessful();
        $this->assertSame(1, $report->runs()->count());

        $report->forceFill(['next_run_at' => Carbon::now()->subMinute()])->save();
        $this->artisan('reports:dispatch daily --sync')->assertSuccessful();

        $report->refresh();

        $this->assertSame(1, $report->runs()->count(), 'No second run for the same window.');
        $this->assertTrue($report->next_run_at?->isFuture(), 'The schedule must have moved on.');
    }

    public function test_one_reports_failure_does_not_affect_its_peers(): void
    {
        Queue::fake();
        Report::factory()->due()->count(3)->create();

        $this->artisan('reports:dispatch daily')->assertSuccessful();

        // Independent jobs: the queue holds three, and nothing links their fates.
        Queue::assertPushed(GenerateReportJob::class, 3);
    }

    public function test_a_single_report_can_be_dispatched_by_id(): void
    {
        Queue::fake();
        $report = Report::factory()->notYetDue()->create();

        $this->artisan("reports:dispatch daily --report={$report->id}")->assertSuccessful();

        Queue::assertPushed(
            GenerateReportJob::class,
            static fn (GenerateReportJob $job): bool => $job->reportId === $report->id,
        );
    }

    public function test_dispatching_an_unknown_report_id_fails(): void
    {
        $this->artisan('reports:dispatch daily --report=01a00000-0000-7000-8000-000000000000')->assertFailed();
    }

    public function test_the_scheduler_registers_both_periods(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(static fn ($event): string => (string) $event->command);

        $this->assertTrue($commands->contains(fn (string $c): bool => str_contains($c, 'reports:dispatch daily')));
        $this->assertTrue($commands->contains(fn (string $c): bool => str_contains($c, 'reports:dispatch weekly')));
    }
}
