<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use App\Mail\ReportReadyMail;
use App\Models\Report\Report;
use App\Enums\ReportRunStatus;
use Illuminate\Support\Carbon;
use App\Models\Report\ReportRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use App\Services\Report\ReportGenerationService;
use App\Repositories\Contracts\Report\ReportRepositoryInterface;
use App\Repositories\Contracts\Report\ReportRunRepositoryInterface;

/**
 * Generates one report for one window.
 *
 * This is the unit of work the whole scalability answer rests on: one job per
 * report, independent of every other, so a backlog drains proportionally faster
 * with each additional worker and one report's failure cannot affect its peers.
 */
class GenerateReportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Consecutive scheduled failures before the subscription is paused. A report
     * that fails every tick forever is a self-inflicted denial of service against
     * the search cluster; pausing turns an unbounded retry storm into a visible,
     * inspectable state that one status write reverses.
     */
    public const FAILURE_THRESHOLD = 5;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * Unique only while it is queued/running, not forever — the same report and
     * window must be re-runnable after a failure.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $reportId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
    ) {
        $this->onQueue('reports');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function uniqueId(): string
    {
        return "report:{$this->reportId}:{$this->periodStart}:{$this->periodEnd}";
    }

    public function handle(
        ReportGenerationService $generator,
        ReportRepositoryInterface $reports,
        ReportRunRepositoryInterface $runs,
    ): void {
        $report = Report::query()->find($this->reportId);

        if (! $report instanceof Report) {
            Log::warning('report.generate.missing', ['report_id' => $this->reportId]);

            return;
        }

        $from = Carbon::parse($this->periodStart);
        $to = Carbon::parse($this->periodEnd);

        // Claim BEFORE doing any work. The claim is an insert against a unique
        // index, so two workers racing for the same window resolve at the
        // database rather than both producing a report. ShouldBeUnique is a
        // second, weaker line of defence: it depends on a working cache lock,
        // which can be lost; a unique index cannot.
        $run = $runs->claimWindow($report, $from, $to);

        if (! $run instanceof ReportRun) {
            Log::info('report.generate.already_claimed', [
                'report_id' => $report->getKey(),
                'period_start' => $from->toIso8601String(),
            ]);

            // The window is owned by someone else, or was already produced. If it
            // already SUCCEEDED, the schedule still has to move on — otherwise the
            // report stays permanently due and every tick re-dispatches a job that
            // does nothing but re-check the same window.
            $this->advanceIfWindowAlreadySucceeded($report, $reports, $from, $to);

            return;
        }

        try {
            $result = $generator->generate($report, $from, $to);
        } catch (Throwable $exception) {
            $runs->markFailed($run, $exception);

            // The schedule is deliberately NOT advanced, so the window is retried
            // rather than silently skipped.
            $this->registerFailure($report, $reports, $exception);

            throw $exception;
        }

        $runs->markSucceeded($run, [
            'rows' => $result->rows(),
            'total_matched' => $result->histogram->total,
            'file_path' => $result->filePath,
            'file_size' => $result->fileSize,
            'duration_ms' => $result->wallClockMs,
            'query_took_ms' => $result->queryTookMs,
            'export_ms' => $result->exportMs,
        ]);

        $reports->markRunSucceeded($report, $to);

        Log::info('report.generate.succeeded', [
            'report_id' => $report->getKey(),
            'run_id' => $run->getKey(),
            'rows' => $result->rows(),
            'total_matched' => $result->histogram->total,
            'query_took_ms' => $result->queryTookMs,
            'duration_ms' => $result->wallClockMs,
        ]);

        $this->deliver($report, $run->refresh(), $runs);
    }

    /**
     * Recorded when every retry is exhausted.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('report.generate.failed', [
            'report_id' => $this->reportId,
            'period_start' => $this->periodStart,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * A window that has already been produced is done: advance the schedule so the
     * dispatcher stops offering it. A window still RUNNING is left alone — the
     * worker that owns it will advance the schedule when it finishes.
     */
    private function advanceIfWindowAlreadySucceeded(
        Report $report,
        ReportRepositoryInterface $reports,
        Carbon $from,
        Carbon $to,
    ): void {
        $existing = $report->runs()
            ->where('period_start', $from)
            ->where('period_end', $to)
            ->first();

        if ($existing instanceof ReportRun && $existing->status === ReportRunStatus::Succeeded) {
            $reports->markRunSucceeded($report, $to);
        }
    }

    /**
     * Delivery is a step distinct from generation: a mail failure must not discard
     * a correctly generated report or cause the histogram to be recomputed on
     * retry. This is also the seam a multi-channel delivery design would plug into.
     */
    private function deliver(Report $report, ReportRun $run, ReportRunRepositoryInterface $runs): void
    {
        $recipient = $report->user?->email;

        if ($recipient === null || $recipient === '') {
            return;
        }

        try {
            Mail::to($recipient)->send(new ReportReadyMail($report, $run));
            $runs->recordDelivery($run);
        } catch (Throwable $exception) {
            $runs->recordDelivery($run, $exception);

            Log::warning('report.deliver.failed', [
                'report_id' => $report->getKey(),
                'run_id' => $run->getKey(),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function registerFailure(Report $report, ReportRepositoryInterface $reports, Throwable $exception): void
    {
        $failures = $reports->markRunFailed($report);

        Log::warning('report.generate.error', [
            'report_id' => $report->getKey(),
            'consecutive_failures' => $failures,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        if ($failures >= self::FAILURE_THRESHOLD) {
            $reports->pause($report);

            Log::error('report.paused', [
                'report_id' => $report->getKey(),
                'reason' => 'consecutive_failures',
                'consecutive_failures' => $failures,
            ]);
        }
    }
}
