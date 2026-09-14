<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * How often a report runs, and — just as importantly — what window each run covers.
 *
 * The windowing logic lives on the enum rather than being spread across the
 * service and the scheduler as `match ($report->period)` blocks, because the
 * period and the behaviour that depends on it are the same fact.
 *
 * Windows are CALENDAR-aligned in the configured report timezone, never
 * "now minus N". A relative window would produce overlapping or gapped reports
 * whenever a run was late or retried — and retries are expected.
 *
 * Every method computes in the report timezone but RETURNS UTC. The timezone is
 * how the boundary is decided, not how the value is stored: Eloquent's datetime
 * cast formats a Carbon in whatever timezone the instance carries and reads it
 * back as the app timezone, so persisting a Tehran-tz Carbon would silently shift
 * the instant by 3.5 hours. Callers that need a local representation convert
 * explicitly — see HistogramExcelWriter.
 */
enum ReportPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * First instant of the window a run executing at $runAt should cover.
     *
     * A daily run covers the previous calendar day; a weekly run the previous
     * calendar week. The window is always closed — never the period in progress —
     * so a report can never be sent with partial data.
     */
    public function windowStart(Carbon $runAt): Carbon
    {
        $local = $runAt->copy()->timezone($this->timezone());

        return (match ($this) {
            self::Daily => $local->subDay()->startOfDay(),
            self::Weekly => $local->subWeek()->startOfWeek(),
        })->utc();
    }

    /**
     * Last instant of that same window.
     */
    public function windowEnd(Carbon $runAt): Carbon
    {
        $local = $runAt->copy()->timezone($this->timezone());

        return (match ($this) {
            self::Daily => $local->subDay()->endOfDay(),
            self::Weekly => $local->subWeek()->endOfWeek(),
        })->utc();
    }

    /**
     * When a report should next run, given the end of the window it just covered.
     *
     * Advancing from the window end rather than from wall-clock "now" means a run
     * that executed late does not permanently shift the schedule forward.
     */
    public function nextRunAt(Carbon $from): Carbon
    {
        $local = $from->copy()->timezone($this->timezone());

        return (match ($this) {
            self::Daily => $local->addDay()->startOfDay(),
            self::Weekly => $local->addWeek()->startOfWeek(),
        })->utc();
    }

    /**
     * The first scheduled run for a newly created report: the next boundary after now.
     */
    public function firstRunAt(Carbon $createdAt): Carbon
    {
        $local = $createdAt->copy()->timezone($this->timezone());

        return (match ($this) {
            self::Daily => $local->addDay()->startOfDay(),
            self::Weekly => $local->addWeek()->startOfWeek(),
        })->utc();
    }

    public function label(): string
    {
        return match ($this) {
            self::Daily => trans('messages.period_daily'),
            self::Weekly => trans('messages.period_weekly'),
        };
    }

    private function timezone(): string
    {
        return (string) config('search.histogram.timezone', 'Asia/Tehran');
    }
}
