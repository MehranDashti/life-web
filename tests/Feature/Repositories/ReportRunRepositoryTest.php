<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use Tests\TestCase;
use RuntimeException;
use App\Models\Report\Report;
use App\Enums\ReportRunStatus;
use Illuminate\Support\Carbon;
use App\Models\Report\ReportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Repositories\Contracts\Report\ReportRunRepositoryInterface;

/**
 * claimWindow is the single mechanism preventing duplicate reports and duplicate
 * emails, so every branch of it is covered here explicitly. Getting one of these
 * wrong is either a double-send or a window that can never be retried.
 */
class ReportRunRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ReportRunRepositoryInterface $runs;

    private Report $report;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runs = app(ReportRunRepositoryInterface::class);
        $this->report = Report::factory()->create();
        $this->from = Carbon::parse('2024-12-18T00:00:00Z');
        $this->to = Carbon::parse('2024-12-18T23:59:59Z');
    }

    public function test_an_unclaimed_window_is_claimed_as_running(): void
    {
        $run = $this->claim();

        $this->assertInstanceOf(ReportRun::class, $run);
        $this->assertSame(ReportRunStatus::Running, $run->status);
        $this->assertSame(1, $run->attempts);
        $this->assertSame($this->report->id, $run->report_id);
    }

    /**
     * The normal outcome of a duplicate dispatch, not an error.
     */
    public function test_a_window_already_running_and_fresh_is_refused(): void
    {
        $this->claim();

        $this->assertNull($this->claim(), 'A second worker must not take a live window.');
        $this->assertSame(1, $this->report->runs()->count());
    }

    /**
     * Redoing it would re-deliver a report the user already has.
     */
    public function test_a_succeeded_window_is_refused(): void
    {
        $run = $this->claim();
        $this->runs->markSucceeded($run, ['rows' => 1, 'total_matched' => 5]);

        $this->assertNull($this->claim());
        $this->assertSame(1, $this->report->runs()->count());
    }

    /**
     * Without this the unique index makes the first failure permanent.
     */
    public function test_a_failed_window_is_reclaimed_and_the_attempt_counted(): void
    {
        $run = $this->claim();
        $this->runs->markFailed($run, new RuntimeException('cluster unavailable'));

        $reclaimed = $this->claim();

        $this->assertInstanceOf(ReportRun::class, $reclaimed);
        $this->assertSame($run->getKey(), $reclaimed->getKey(), 'It must reuse the row, not create a second.');
        $this->assertSame(ReportRunStatus::Running, $reclaimed->status);
        $this->assertSame(2, $reclaimed->attempts);
        $this->assertNull($reclaimed->error, 'The previous error must be cleared on retry.');
        $this->assertSame(1, $this->report->runs()->count());
    }

    /**
     * A worker that died mid-run would otherwise block the window forever.
     */
    public function test_a_stale_running_window_is_reclaimed(): void
    {
        $run = $this->claim();
        $run->forceFill([
            'updated_at' => Carbon::now()->subMinutes($this->runs->staleAfterMinutes() + 1),
        ])->saveQuietly();

        $reclaimed = $this->claim();

        $this->assertInstanceOf(ReportRun::class, $reclaimed);
        $this->assertSame($run->getKey(), $reclaimed->getKey());
        $this->assertSame(2, $reclaimed->attempts);
    }

    public function test_a_running_window_just_inside_the_staleness_window_is_still_refused(): void
    {
        $run = $this->claim();
        $run->forceFill([
            'updated_at' => Carbon::now()->subMinutes($this->runs->staleAfterMinutes() - 1),
        ])->saveQuietly();

        $this->assertNull($this->claim());
    }

    public function test_different_windows_of_the_same_report_are_independent(): void
    {
        $this->claim();
        $second = $this->runs->claimWindow(
            $this->report,
            $this->from->copy()->addDay(),
            $this->to->copy()->addDay(),
        );

        $this->assertInstanceOf(ReportRun::class, $second);
        $this->assertSame(2, $this->report->runs()->count());
    }

    public function test_the_same_window_of_different_reports_is_independent(): void
    {
        $this->claim();
        $other = Report::factory()->create();

        $this->assertInstanceOf(ReportRun::class, $this->runs->claimWindow($other, $this->from, $this->to));
    }

    public function test_marking_succeeded_records_the_metrics_and_clears_the_error(): void
    {
        $run = $this->claim();
        $this->runs->markFailed($run, new RuntimeException('earlier'));

        $this->runs->markSucceeded($run, [
            'rows' => 4,
            'total_matched' => 21,
            'file_path' => 'r/20241218-20241218.xlsx',
            'file_size' => 5130,
            'duration_ms' => 32,
            'query_took_ms' => 3,
            'export_ms' => 25,
        ]);

        $fresh = $run->fresh();

        $this->assertSame(ReportRunStatus::Succeeded, $fresh->status);
        $this->assertSame(4, $fresh->rows);
        $this->assertSame(21, $fresh->total_matched);
        $this->assertSame(3, $fresh->query_took_ms);
        $this->assertSame(25, $fresh->export_ms);
        $this->assertNull($fresh->error);
        $this->assertTrue($fresh->hasFile());
    }

    /**
     * The exception class is recorded, not just the message — a bare message rarely
     * identifies which subsystem failed.
     */
    public function test_marking_failed_records_the_exception_class_and_message(): void
    {
        $run = $this->claim();

        $this->runs->markFailed($run, new RuntimeException('cluster unavailable'));

        $error = (string) $run->fresh()->error;

        $this->assertStringContainsString(RuntimeException::class, $error);
        $this->assertStringContainsString('cluster unavailable', $error);
        $this->assertSame(ReportRunStatus::Failed, $run->fresh()->status);
    }

    public function test_recording_a_successful_delivery_stamps_the_time(): void
    {
        $run = $this->claim();

        $this->runs->recordDelivery($run);

        $this->assertNotNull($run->fresh()->delivered_at);
        $this->assertNull($run->fresh()->delivery_error);
    }

    /**
     * A delivery failure must be visible through the API without discarding the
     * generated artifact.
     */
    public function test_recording_a_failed_delivery_stores_the_reason(): void
    {
        $run = $this->claim();

        $this->runs->recordDelivery($run, new RuntimeException('smtp down'));

        $this->assertNull($run->fresh()->delivered_at);
        $this->assertStringContainsString('smtp down', (string) $run->fresh()->delivery_error);
    }

    private function claim(): ?ReportRun
    {
        return $this->runs->claimWindow($this->report, $this->from, $this->to);
    }
}
