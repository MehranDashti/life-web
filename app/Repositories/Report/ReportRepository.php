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
    public function __construct(Report $model)
    {
        parent::__construct($model);
    }

    public function eachDueForDispatch(ReportPeriod $period, Carbon $now, Closure $callback, int $chunkSize = 200): int
    {
        $dispatched = 0;

        Report::query()
            ->where('status', ReportStatus::Active)
            ->where('period', $period)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            // Ordered by primary key so chunkById is stable even as rows are
            // updated by the workers processing an earlier chunk.
            ->chunkById($chunkSize, function (EloquentCollection $reports) use ($callback, &$dispatched): void {
                $dispatched += $reports->count();
                $callback($reports);
            });

        return $dispatched;
    }

    public function markRunSucceeded(Report $report, Carbon $windowEnd): void
    {
        $report->forceFill([
            'last_run_at' => $windowEnd,

            // The NEXT boundary after now, not window-end plus one period.
            //
            // Windows are derived from the moment of dispatch — a daily run always
            // covers "yesterday" — so advancing from the window end can land in the
            // past, leaving the report permanently due and re-dispatching a job every
            // tick that finds the window already produced and does nothing. Anchoring
            // to now guarantees forward progress.
            //
            // The trade-off is that missed windows are not backfilled: a report that
            // was down for three days resumes from the next boundary rather than
            // replaying what it missed. Backfill is an explicit non-goal.
            'next_run_at' => $report->period->firstRunAt(Carbon::now()),
            'consecutive_failures' => 0,
        ])->saveQuietly();
    }

    public function markRunFailed(Report $report): int
    {
        $failures = $report->consecutive_failures + 1;

        $report->forceFill(['consecutive_failures' => $failures])->saveQuietly();

        return $failures;
    }

    public function pause(Report $report): void
    {
        $report->forceFill(['status' => ReportStatus::Paused])->saveQuietly();
    }
}
