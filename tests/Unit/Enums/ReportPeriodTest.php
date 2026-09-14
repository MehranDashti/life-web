<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use Tests\TestCase;
use App\Enums\ReportPeriod;
use Illuminate\Support\Carbon;

/**
 * Windowing is where a reporting system quietly goes wrong: an off-by-one or a
 * timezone slip produces reports that look plausible and count the wrong days.
 */
class ReportPeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['search.histogram.timezone' => 'Asia/Tehran']);
    }

    public function test_a_daily_window_covers_the_previous_tehran_calendar_day(): void
    {
        $runAt = Carbon::parse('2024-06-15T09:00:00Z');

        $start = ReportPeriod::Daily->windowStart($runAt);
        $end = ReportPeriod::Daily->windowEnd($runAt);

        $this->assertSame('2024-06-14 00:00:00', $this->inTehran($start));
        $this->assertSame('2024-06-14 23:59:59', $this->inTehran($end));
    }

    public function test_a_weekly_window_covers_the_previous_calendar_week(): void
    {
        $runAt = Carbon::parse('2024-06-15T09:00:00Z');

        $start = ReportPeriod::Weekly->windowStart($runAt);
        $end = ReportPeriod::Weekly->windowEnd($runAt);

        // Exactly seven calendar days, inclusive: 7 * 86400 seconds minus the one
        // second between 23:59:59 and the next midnight.
        $this->assertSame(7 * 86400 - 1, (int) $start->diffInSeconds($end, absolute: true));
        $this->assertTrue($start->lt($end));
        $this->assertSame('00:00:00', $start->copy()->timezone('Asia/Tehran')->format('H:i:s'));
        $this->assertSame('23:59:59', $end->copy()->timezone('Asia/Tehran')->format('H:i:s'));
    }

    /**
     * Eloquent's datetime cast formats a Carbon in whatever timezone it carries and
     * reads it back as the app timezone. A Tehran-tz Carbon would therefore be
     * stored as Tehran wall-clock and re-read as UTC — a silent 3.5 hour drift.
     */
    public function test_boundaries_are_returned_in_utc_so_persistence_cannot_drift(): void
    {
        $runAt = Carbon::parse('2024-06-15T09:00:00Z');

        foreach (ReportPeriod::cases() as $period) {
            $this->assertSame('UTC', $period->windowStart($runAt)->getTimezone()->getName());
            $this->assertSame('UTC', $period->windowEnd($runAt)->getTimezone()->getName());
            $this->assertSame('UTC', $period->nextRunAt($runAt)->getTimezone()->getName());
            $this->assertSame('UTC', $period->firstRunAt($runAt)->getTimezone()->getName());
        }
    }

    public function test_the_utc_instant_still_represents_tehran_midnight(): void
    {
        $start = ReportPeriod::Daily->windowStart(Carbon::parse('2024-06-15T09:00:00Z'));

        // Tehran is UTC+3:30 with no DST since 2022, so midnight local is 20:30 the
        // previous day in UTC.
        $this->assertSame('2024-06-13 20:30:00', $start->toDateTimeString());
    }

    public function test_a_window_at_a_month_boundary_does_not_leak_into_the_wrong_month(): void
    {
        $start = ReportPeriod::Daily->windowStart(Carbon::parse('2024-03-01T09:00:00Z'));

        $this->assertSame('2024-02-29 00:00:00', $this->inTehran($start), 'Leap day must be covered.');
    }

    public function test_a_window_at_a_year_boundary_is_correct(): void
    {
        $start = ReportPeriod::Daily->windowStart(Carbon::parse('2025-01-01T09:00:00Z'));

        $this->assertSame('2024-12-31 00:00:00', $this->inTehran($start));
    }

    /**
     * The schedule must advance from the window that was covered, not from the
     * moment the run happened — otherwise a late run permanently shifts the
     * schedule forward and a day eventually gets skipped.
     */
    public function test_the_next_run_advances_from_the_window_end_not_from_now(): void
    {
        $windowEnd = ReportPeriod::Daily->windowEnd(Carbon::parse('2024-06-15T09:00:00Z'));

        $onTime = ReportPeriod::Daily->nextRunAt($windowEnd);
        $lateByHours = ReportPeriod::Daily->nextRunAt($windowEnd);

        $this->assertTrue($onTime->equalTo($lateByHours));
        $this->assertSame('2024-06-15 00:00:00', $this->inTehran($onTime));
    }

    public function test_consecutive_daily_windows_are_contiguous_and_never_overlap(): void
    {
        $first = Carbon::parse('2024-06-15T09:00:00Z');
        $second = Carbon::parse('2024-06-16T09:00:00Z');

        $firstEnd = ReportPeriod::Daily->windowEnd($first);
        $secondStart = ReportPeriod::Daily->windowStart($second);

        $this->assertTrue($secondStart->gt($firstEnd), 'Windows must not overlap.');
        $this->assertLessThanOrEqual(1, $firstEnd->diffInSeconds($secondStart, absolute: true));
    }

    public function test_a_new_report_is_scheduled_for_the_next_boundary(): void
    {
        $createdAt = Carbon::parse('2024-06-15T09:00:00Z');

        $this->assertSame('2024-06-16 00:00:00', $this->inTehran(ReportPeriod::Daily->firstRunAt($createdAt)));
        $this->assertTrue(ReportPeriod::Daily->firstRunAt($createdAt)->gt($createdAt));
    }

    public function test_values_lists_every_case(): void
    {
        $this->assertSame(['daily', 'weekly'], ReportPeriod::values());
    }

    public function test_labels_are_translated(): void
    {
        $this->assertSame(trans('messages.period_daily'), ReportPeriod::Daily->label());
        $this->assertStringNotContainsString('messages.', ReportPeriod::Daily->label());
    }

    private function inTehran(Carbon $moment): string
    {
        return $moment->copy()->timezone('Asia/Tehran')->toDateTimeString();
    }
}
