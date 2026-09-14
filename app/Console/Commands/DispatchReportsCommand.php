<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReportPeriod;
use App\Models\Report\Report;
use Illuminate\Support\Carbon;
use App\Jobs\GenerateReportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Repositories\Contracts\Report\ReportRepositoryInterface;

/**
 * Enqueues the reports due for a period. It computes nothing itself.
 *
 * This is the core of the answer to "how does this scale as users grow": the
 * scheduler runs one indexed range scan and enqueues a job per due report, so its
 * cost is proportional to the work available and completely independent of the
 * corpus size. A scheduler that generated reports inline would serialise every
 * user behind one process — exactly the bottleneck the task asks about.
 */
class DispatchReportsCommand extends Command
{
    protected $signature = 'reports:dispatch
        {period : daily or weekly}
        {--sync : Run the jobs inline instead of queueing them}
        {--report= : Dispatch a single report by id, ignoring whether it is due}
        {--jitter=60 : Spread enqueued jobs across this many seconds}';

    protected $description = 'Enqueue a generation job for every report due in the given period';

    public function handle(ReportRepositoryInterface $reports): int
    {
        $period = ReportPeriod::tryFrom((string) $this->argument('period'));

        if (! $period instanceof ReportPeriod) {
            $this->components->error(
                'period must be one of: '.implode(', ', ReportPeriod::values()),
            );

            return self::FAILURE;
        }

        $now = Carbon::now();
        $jitter = max(0, (int) $this->option('jitter'));

        if ($this->option('report') !== null) {
            return $this->dispatchSingle((string) $this->option('report'), $now);
        }

        $dispatched = $reports->eachDueForDispatch(
            $period,
            $now,
            function ($due) use ($now, $jitter): void {
                foreach ($due as $report) {
                    $this->dispatchReport($report, $now, $jitter);
                }
            },
        );

        Log::info('reports.dispatch', [
            'period' => $period->value,
            'dispatched' => $dispatched,
            'at' => $now->toIso8601String(),
        ]);

        $this->components->info("Dispatched {$dispatched} {$period->value} report(s).");

        return self::SUCCESS;
    }

    private function dispatchSingle(string $reportId, Carbon $now): int
    {
        $report = Report::query()->find($reportId);

        if (! $report instanceof Report) {
            $this->components->error("No report with id [{$reportId}].");

            return self::FAILURE;
        }

        $this->dispatchReport($report, $now, jitter: 0);
        $this->components->info("Dispatched report [{$reportId}].");

        return self::SUCCESS;
    }

    private function dispatchReport(Report $report, Carbon $now, int $jitter): void
    {
        $from = $report->period->windowStart($now);
        $to = $report->period->windowEnd($now);

        $job = new GenerateReportJob(
            reportId: (string) $report->getKey(),
            periodStart: $from->toIso8601String(),
            periodEnd: $to->toIso8601String(),
        );

        if ($this->option('sync')) {
            dispatch_sync($job);

            return;
        }

        // Reports concentrated on a single tick would hit Elasticsearch as one
        // burst. Spreading the enqueue smooths that without needing rate limiting.
        $delay = $jitter > 0 ? random_int(0, $jitter) : 0;

        dispatch($job->delay(now()->addSeconds($delay)));
    }
}
