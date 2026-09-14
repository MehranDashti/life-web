<?php

declare(strict_types=1);

namespace App\Repositories\Report;

use Closure;
use App\Enums\ReportPeriod;
use App\Enums\ReportStatus;
use App\Models\Report\Report;
use Illuminate\Support\Carbon;
use App\Repositories\Contracts\BaseRepository;
use App\Repositories\Contracts\Report\ReportRepositoryInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ReportRepository extends BaseRepository implements ReportRepositoryInterface
{
    /**
     * Bound to the Report model; everything generic comes from BaseRepository.
     */
    public function __construct(Report $model)
    {
        parent::__construct($model);
    }

    /**
     * Stream the reports due for dispatch, in chunks.
     *
     * Chunked rather than collected so a large backlog never loads into memory at
     * once — the dispatcher's cost has to track the work available, not the size of
     * the table. chunkById orders by primary key, which keeps the pages stable even
     * as workers processing an earlier chunk update the rows behind it.
     */
    public function eachDueForDispatch(ReportPeriod $period, Carbon $now, Closure $callback, int $chunkSize = 200): int
    {
        $dispatched = 0;

        Report::query()
            ->where('status', ReportStatus::Active)
            ->where('period', $period)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->chunkById($chunkSize, function (EloquentCollection $reports) use ($callback, &$dispatched): void {
                $dispatched += $reports->count();
                $callback($reports);
            });

        return $dispatched;
    }

    /**
     * Record a successful run: advance the schedule and clear the failure streak.
     *
     * `next_run_at` becomes the next boundary after NOW, not the window end plus one
     * period. Windows are derived from the moment of dispatch — a daily run always
     * covers "yesterday" — so advancing from the window end lands in the past,
     * leaving the report permanently due and re-dispatching a job on every tick that
     * finds the window already produced and does nothing. Anchoring to now
     * guarantees forward progress.
     *
     * The trade-off is that missed windows are not backfilled: a report that was
     * down for three days resumes from the next boundary rather than replaying what
     * it missed. Backfill is an explicit non-goal.
     */
    public function markRunSucceeded(Report $report, Carbon $windowEnd): void
    {
        $report->forceFill([
            'last_run_at' => $windowEnd,
            'next_run_at' => $report->period->firstRunAt(Carbon::now()),
            'consecutive_failures' => 0,
        ])->saveQuietly();
    }

    /**
     * Record a failed run and return the new consecutive-failure count.
     *
     * The schedule is deliberately not advanced, so the window is retried rather
     * than silently skipped.
     */
    public function markRunFailed(Report $report): int
    {
        $failures = $report->consecutive_failures + 1;

        $report->forceFill(['consecutive_failures' => $failures])->saveQuietly();

        return $failures;
    }

    /**
     * Take a report out of dispatch after repeated failures.
     *
     * A subscription that fails every tick forever is a self-inflicted denial of
     * service against the search cluster; pausing turns an unbounded retry storm
     * into a visible state that one status write reverses.
     */
    public function pause(Report $report): void
    {
        $report->forceFill(['status' => ReportStatus::Paused])->saveQuietly();
    }
}
