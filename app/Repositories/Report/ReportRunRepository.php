<?php

declare(strict_types=1);

namespace App\Repositories\Report;

use Throwable;
use App\Models\Report\Report;
use App\Enums\ReportRunStatus;
use Illuminate\Support\Carbon;
use App\Models\Report\ReportRun;
use App\Repositories\Contracts\BaseRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Repositories\Contracts\Report\ReportRunRepositoryInterface;

class ReportRunRepository extends BaseRepository implements ReportRunRepositoryInterface
{
    /**
     * A worker whose timeout has elapsed is assumed dead; without this a crash
     * mid-run would block the window permanently.
     */
    private const int STALE_AFTER_MINUTES = 15;

    /**
     * Bound to the ReportRun model; everything generic comes from BaseRepository.
     */
    public function __construct(ReportRun $model)
    {
        parent::__construct($model);
    }

    /**
     * Claim a report and window for execution, or return null if it is already owned.
     *
     * The claim is an INSERT against a unique index, so two workers racing for the
     * same window resolve deterministically at the database — a cache lock can be
     * raced, a unique index cannot. A violation means the window already has a row,
     * and whether this worker may take it over depends on what happened to it last
     * time; see reclaim().
     */
    public function claimWindow(Report $report, Carbon $periodStart, Carbon $periodEnd): ?ReportRun
    {
        try {
            $run = new ReportRun;
            $run->fill([
                'report_id' => $report->getKey(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => ReportRunStatus::Running,
                'attempts' => 1,
            ]);
            $run->saveQuietly();

            return $run;
        } catch (UniqueConstraintViolationException) {
            return $this->reclaim($report, $periodStart, $periodEnd);
        }
    }

    /**
     * How long a `running` row may sit untouched before another worker may take it.
     */
    public function staleAfterMinutes(): int
    {
        return self::STALE_AFTER_MINUTES;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function markSucceeded(ReportRun $run, array $attributes): void
    {
        $run->forceFill(array_merge($attributes, [
            'status' => ReportRunStatus::Succeeded,
            'error' => null,
        ]))->saveQuietly();
    }

    /**
     * Record a failure on the run.
     *
     * The exception class is stored alongside the message because a bare message
     * rarely identifies which subsystem failed.
     */
    public function markFailed(ReportRun $run, Throwable $exception): void
    {
        $run->forceFill([
            'status' => ReportRunStatus::Failed,
            'error' => $exception::class.': '.$exception->getMessage(),
        ])->saveQuietly();
    }

    /**
     * Record the outcome of delivery, separately from generation.
     *
     * A delivery failure is stored on the run rather than only logged, so it is
     * visible through the API without discarding the generated artifact.
     */
    public function recordDelivery(ReportRun $run, ?Throwable $failure = null): void
    {
        $run->forceFill($failure instanceof Throwable
            ? ['delivery_error' => $failure::class.': '.$failure->getMessage()]
            : ['delivered_at' => Carbon::now(), 'delivery_error' => null],
        )->saveQuietly();
    }

    /**
     * Decide whether an existing row for this window may be taken over.
     *
     * A succeeded window is refused — redoing it would re-deliver a report the user
     * already has. A failed window is reclaimed, because the unique index would
     * otherwise make the first failure permanent. A running window is refused while
     * fresh and reclaimed once stale, so a worker that died mid-run cannot block the
     * window forever.
     */
    private function reclaim(Report $report, Carbon $periodStart, Carbon $periodEnd): ?ReportRun
    {
        $existing = ReportRun::query()
            ->where('report_id', $report->getKey())
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();

        if (! $existing instanceof ReportRun) {
            return null;
        }

        if ($existing->status === ReportRunStatus::Succeeded) {
            return null;
        }

        $isStaleRun = $existing->status === ReportRunStatus::Running
            && $existing->updated_at->lt(Carbon::now()->subMinutes(self::STALE_AFTER_MINUTES));

        if ($existing->status !== ReportRunStatus::Failed && ! $isStaleRun) {
            return null;
        }

        $existing->forceFill([
            'status' => ReportRunStatus::Running,
            'attempts' => $existing->attempts + 1,
            'error' => null,
        ])->saveQuietly();

        return $existing;
    }
}
