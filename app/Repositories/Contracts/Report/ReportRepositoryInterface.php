<?php

declare(strict_types=1);

namespace App\Repositories\Contracts\Report;

use Closure;
use App\Enums\ReportPeriod;
use App\Models\Report\Report;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Repositories\Contracts\BaseRepositoryInterface;

interface ReportRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Stream the reports due for dispatch, in chunks.
     *
     * Chunked rather than returning a collection because a large backlog must
     * never load into memory at once — the dispatcher's cost has to stay
     * proportional to the work, not to the size of the table.
     *
     * @param  Closure(Collection<int, Report>): void  $callback
     * @return int  the number of reports handed to the callback
     */
    public function eachDueForDispatch(ReportPeriod $period, Carbon $now, Closure $callback, int $chunkSize = 200): int;

    /**
     * Record a successful run: advance the schedule and clear the failure streak.
     */
    public function markRunSucceeded(Report $report, Carbon $windowEnd): void;

    /**
     * Record a failed run. The schedule is deliberately NOT advanced, so the
     * window is retried rather than silently skipped.
     *
     * @return int  the new consecutive-failure count
     */
    public function markRunFailed(Report $report): int;

    public function pause(Report $report): void;
}
