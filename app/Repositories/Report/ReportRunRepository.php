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

    public function __construct(ReportRun $model)
    {
        parent::__construct($model);
    }

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
            // The window already has a row. Whether this worker may take it over
            // depends on what happened to it last time.
            return $this->reclaim($report, $periodStart, $periodEnd);
        }
    }

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

    public function markFailed(ReportRun $run, Throwable $exception): void
    {
        $run->forceFill([
            'status' => ReportRunStatus::Failed,
            // Class name included because the message alone rarely identifies the
            // subsystem that failed.
            'error' => $exception::class.': '.$exception->getMessage(),
        ])->saveQuietly();
    }

    public function recordDelivery(ReportRun $run, ?Throwable $failure = null): void
    {
        $run->forceFill($failure instanceof Throwable
            ? ['delivery_error' => $failure::class.': '.$failure->getMessage()]
            : ['delivered_at' => Carbon::now(), 'delivery_error' => null],
        )->saveQuietly();
    }

    private function reclaim(Report $report, Carbon $periodStart, Carbon $periodEnd): ?ReportRun
    {
        $existing = ReportRun::query()
            ->where('report_id', $report->getKey())
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();

        if (! $existing instanceof ReportRun) {
            // Lost the race and then the row vanished — treat as claimed and let
            // the other worker finish.
            return null;
        }

        // Already produced. Redoing it would re-deliver a report the user has.
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
