<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled commands
|--------------------------------------------------------------------------
|
| The scheduler only DISPATCHES. Each tick runs one indexed range scan and
| enqueues a job per due report; the work itself happens on the queue, where it
| scales with worker count.
|
| The task explicitly states that the exact day of week and send time do not
| matter, so these are simply early-morning local times.
|
| withoutOverlapping() matters: a dispatch that is still running when the next
| tick fires must not double-enqueue. Idempotency is still guaranteed further
| down by the unique index on report_runs — this just avoids the wasted work.
|
*/

Schedule::command('reports:dispatch daily')
    ->dailyAt('06:00')
    ->timezone(config('search.histogram.timezone', 'Asia/Tehran'))
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('reports:dispatch weekly')
    ->weeklyOn(6, '07:00') // Saturday — the start of the Iranian week
    ->timezone(config('search.histogram.timezone', 'Asia/Tehran'))
    ->withoutOverlapping()
    ->runInBackground();
