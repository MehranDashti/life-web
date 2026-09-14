<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Report\Report;
use Illuminate\Support\Carbon;
use App\Services\Report\Data\GenerationResult;
use App\Adapters\Contracts\Data\HistogramQuery;
use App\Adapters\Contracts\SearchAdapterInterface;

/**
 * Turns a report subscription plus a window into a computed histogram and an
 * exported file.
 *
 * No query construction happens here: the aggregation shape lives behind
 * SearchAdapterInterface, which is what keeps `size: 0` the only way this system
 * counts documents.
 */
final readonly class ReportGenerationService
{
    public function __construct(
        private SearchAdapterInterface $search,
        private HistogramExcelWriter $writer,
    ) {}

    /**
     * Compute the histogram for a report over an explicit window.
     */
    public function compute(Report $report, Carbon $from, Carbon $to): GenerationResult
    {
        $startedAt = microtime(true);

        $histogram = $this->search->dailyHistogram($this->queryFor($report, $from, $to));

        return new GenerationResult(
            histogram: $histogram,
            queryTookMs: $histogram->tookMs,
            wallClockMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    /**
     * Compute and export in one step — what the queued job calls.
     */
    public function generate(Report $report, Carbon $from, Carbon $to): GenerationResult
    {
        $result = $this->compute($report, $from, $to);

        $exportStartedAt = microtime(true);
        $memoryBefore = memory_get_peak_usage(true);

        $written = $this->writer->write($report, $from, $to, $result->histogram);

        return $result->withFile(
            path: $written->path,
            size: $written->size,
            exportMs: (int) round((microtime(true) - $exportStartedAt) * 1000),
            peakMemoryBytes: max(0, memory_get_peak_usage(true) - $memoryBefore),
        );
    }

    public function queryFor(Report $report, Carbon $from, Carbon $to): HistogramQuery
    {
        return new HistogramQuery(
            keywords: $report->keywords,
            from: $from,
            to: $to,
            timezone: (string) config('search.histogram.timezone', 'Asia/Tehran'),
            newsAgencyIds: $report->news_agency_ids ?? [],
            matchAllKeywords: $report->match_all_keywords,
        );
    }
}
