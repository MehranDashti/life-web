<?php

declare(strict_types=1);

namespace App\Repositories\Contracts\Report;

use Throwable;
use App\Models\Report\Report;
use Illuminate\Support\Carbon;
use App\Models\Report\ReportRun;
use App\Repositories\Contracts\BaseRepositoryInterface;

interface ReportRunRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Claim a report + window for execution, or return null if it is already owned.
     *
     * The claim is an INSERT against a unique index, so two workers racing for the
     * same window resolve deterministically at the database — a cache lock can be
     * raced, a unique index cannot.
     *
     * Claiming is not the same as "has never been attempted":
     *  - succeeded  → null. The work is done; redoing it would re-deliver.
     *  - running    → null while fresh, reclaimed once stale, because a worker that
     *                 died mid-run would otherwise block the window forever.
     *  - failed     → reclaimed. A failed window must be retryable, and the unique
     *                 index would otherwise make the first failure permanent.
     */
    public function claimWindow(Report $report, Carbon $periodStart, Carbon $periodEnd): ?ReportRun;

    /**
     * How long a `running` row may sit untouched before another worker may
     * reclaim it.
     */
    public function staleAfterMinutes(): int;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function markSucceeded(ReportRun $run, array $attributes): void;

    public function markFailed(ReportRun $run, Throwable $exception): void;

    public function recordDelivery(ReportRun $run, ?Throwable $failure = null): void;
}
