<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Throwable;
use App\Models\Report\Report;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use App\Services\Search\PostIndexService;
use App\Adapters\Cached\CachedSearchAdapter;
use App\Services\Report\ReportGenerationService;
use App\Adapters\Contracts\SearchAdapterInterface;
use App\Adapters\Elasticsearch\ElasticsearchAdapter;

/**
 * Measures end-to-end report generation, split into the aggregation and the
 * workbook write.
 *
 * The task asks for "report generation time". A single number cannot tell a
 * reader whether the design is query-bound or export-bound, which is exactly what
 * the answer to "what about very large data" turns on — so the two halves are
 * reported separately, along with peak memory to substantiate the streaming claim.
 */
class BenchmarkReportCommand extends Command
{
    protected $signature = 'bench:report
        {--sizes=10000,100000,1000000 : Corpus sizes to sweep}
        {--windows=1,7,365 : Report window lengths in days}
        {--content-words=40 : Body length of each synthetic document}
        {--keywords=تهران : Comma-separated keywords}
        {--output=loadtest/results/bench-report.json : Where to write the raw results}';

    protected $description = 'Measure report generation time and memory across corpus sizes and window lengths';

    public function handle(
        PostIndexService $indexer,
        SearchAdapterInterface $search,
        ReportGenerationService $generator,
    ): int {
        $search = $this->engine($search);

        if (! $search->ping()) {
            $this->components->error('Elasticsearch is unreachable.');

            return self::FAILURE;
        }

        $report = Report::query()->first();

        if (! $report instanceof Report) {
            $this->components->error('No report exists to benchmark. Run `php artisan db:seed` and create one first.');

            return self::FAILURE;
        }

        $report = clone $report;
        $report->keywords = array_values(array_filter(array_map(trim(...), explode(',', (string) $this->option('keywords')))));

        $sizes = array_map(intval(...), explode(',', (string) $this->option('sizes')));
        $windows = array_map(intval(...), explode(',', (string) $this->option('windows')));

        $from = Carbon::parse('2024-01-01T00:00:00Z');
        $to = Carbon::parse('2024-12-31T23:59:59Z');

        $results = [];

        foreach ($sizes as $size) {
            $this->components->info("Corpus size {$size}…");

            try {
                $search->flush();
                $indexer->generateSynthetic(
                    count: $size,
                    from: $from,
                    to: $to,
                    contentWords: (int) $this->option('content-words'),
                );

                if ($search instanceof ElasticsearchAdapter) {
                    $search->forceMerge();
                }

                $search->refresh();

                foreach ($windows as $days) {
                    $results[] = $this->measure($generator, $report, $search->count(), $days, $to);
                }
            } catch (Throwable $exception) {
                $this->components->error("Size {$size} failed: ".$exception->getMessage());
                $results[] = ['documents' => $size, 'failed' => true, 'error' => $exception->getMessage()];
            }
        }

        $this->writeResults($results);
        $this->renderTable($results);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function measure(
        ReportGenerationService $generator,
        Report $report,
        int $documents,
        int $windowDays,
        Carbon $to,
    ): array {
        $from = $to->copy()->subDays($windowDays - 1)->startOfDay();

        // Discarded warm-up, same reasoning as bench:search.
        $generator->compute($report, $from, $to);

        $before = memory_get_peak_usage(true);
        $result = $generator->generate($report, $from, $to);
        $peak = memory_get_peak_usage(true);

        return [
            'documents' => $documents,
            'window_days' => $windowDays,
            'rows' => $result->rows(),
            'matched' => $result->histogram->total,
            'query_ms' => $result->queryTookMs,
            'export_ms' => $result->exportMs,
            'total_ms' => $result->wallClockMs,
            'file_bytes' => $result->fileSize,
            'peak_memory_bytes' => $peak,
            'peak_memory_delta_bytes' => max(0, $peak - $before),
            'failed' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function writeResults(array $results): void
    {
        $path = base_path((string) $this->option('output'));

        @mkdir(dirname($path), 0o775, true);

        file_put_contents($path, json_encode([
            'generated_at' => Carbon::now()->toIso8601String(),
            'php' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->components->info("Raw results written to {$path}");
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function renderTable(array $results): void
    {
        $rows = [];

        foreach ($results as $result) {
            if ($result['failed'] === true) {
                $rows[] = [number_format((int) $result['documents']), 'FAILED', '', '', '', '', ''];

                continue;
            }

            $rows[] = [
                number_format((int) $result['documents']),
                $result['window_days'].' d',
                (string) $result['rows'],
                number_format((int) $result['matched']),
                $result['query_ms'].' ms',
                $result['export_ms'].' ms',
                round(((int) $result['peak_memory_bytes']) / 1024 / 1024, 1).' MB',
            ];
        }

        $this->newLine();
        $this->table(
            ['documents', 'window', 'rows', 'matched', 'query', 'export', 'peak memory'],
            $rows,
        );
    }

    /**
     * Strip the cache decorator so the benchmark characterises Elasticsearch.
     *
     * Measuring through the cache would report near-zero times after the warm-up
     * pass, because every measured iteration issues the same query and would be a
     * hit — the result would describe Redis while claiming to describe the engine.
     * It also restores the concrete-adapter checks below, which drive force-merge
     * and on-disk size and would silently no-op against a decorator.
     *
     * The cache's own effect is measured separately, by `--herd`.
     */
    private function engine(SearchAdapterInterface $search): SearchAdapterInterface
    {
        return $search instanceof CachedSearchAdapter ? $search->inner() : $search;
    }
}
