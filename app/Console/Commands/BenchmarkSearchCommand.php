<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Throwable;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use App\Services\Search\PostIndexService;
use App\Adapters\Contracts\Data\HistogramQuery;
use App\Adapters\Contracts\SearchAdapterInterface;
use App\Adapters\Elasticsearch\ElasticsearchAdapter;

/**
 * Measures how the report aggregation behaves as the corpus grows.
 *
 * Concurrency is held at one on purpose. The task asks two different questions —
 * how the API behaves under load, and how Elasticsearch behaves as data volume
 * grows — and they need separate instruments. Blending them produces a number
 * that explains neither. The API side is measured by the k6 scenarios.
 */
class BenchmarkSearchCommand extends Command
{
    protected $signature = 'bench:search
        {--sizes=10000,100000,1000000 : Corpus sizes to sweep}
        {--iterations=20 : Measured queries per size}
        {--keywords=تهران : Comma-separated keywords to filter on}
        {--window=30 : Report window in days}
        {--content-words=40 : Body length of each synthetic document}
        {--output=loadtest/results/bench-search.json : Where to write the raw results}';

    protected $description = 'Sweep the corpus and measure aggregation time at each size';

    public function handle(PostIndexService $indexer, SearchAdapterInterface $search): int
    {
        if (! $search->ping()) {
            $this->components->error('Elasticsearch is unreachable.');

            return self::FAILURE;
        }

        $sizes = array_map(intval(...), explode(',', (string) $this->option('sizes')));
        $iterations = max(1, (int) $this->option('iterations'));
        $keywords = array_values(array_filter(array_map(trim(...), explode(',', (string) $this->option('keywords')))));
        $windowDays = max(1, (int) $this->option('window'));

        // A fixed publication range across every size, so a fixed-width report
        // window always selects the same FRACTION of the corpus. Without this the
        // curve would measure the window shrinking, not the corpus growing.
        $from = Carbon::parse('2024-01-01T00:00:00Z');
        $to = Carbon::parse('2024-12-31T23:59:59Z');

        $query = new HistogramQuery(
            keywords: $keywords,
            from: $to->copy()->subDays($windowDays),
            to: $to,
            timezone: (string) config('search.histogram.timezone', 'Asia/Tehran'),
        );

        $results = [];

        foreach ($sizes as $size) {
            $this->components->info("Corpus size {$size}…");

            try {
                $results[] = $this->measureSize($indexer, $search, $size, $from, $to, $query, $iterations);
            } catch (Throwable $exception) {
                // Record and continue: a size that cannot complete (circuit breaker,
                // disk pressure) is a result, not a reason to lose the smaller sizes.
                $this->components->error("Size {$size} failed: ".$exception->getMessage());
                $results[] = ['documents' => $size, 'failed' => true, 'error' => $exception->getMessage()];
            }
        }

        $this->writeResults($results, $keywords, $windowDays, $iterations);
        $this->renderTable($results);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function measureSize(
        PostIndexService $indexer,
        SearchAdapterInterface $search,
        int $size,
        Carbon $from,
        Carbon $to,
        HistogramQuery $query,
        int $iterations,
    ): array {
        $search->flush();

        $bar = $this->output->createProgressBar($size);
        $bar->start();

        $import = $indexer->generateSynthetic(
            count: $size,
            from: $from,
            to: $to,
            seed: 1,
            contentWords: (int) $this->option('content-words'),
            onChunk: function (int $documents) use ($bar): void {
                $bar->advance($documents);
            },
        );

        $bar->finish();
        $this->newLine();

        // Merge to one segment per index: an unmerged index makes query time a
        // measure of segment count rather than of corpus size.
        if ($search instanceof ElasticsearchAdapter) {
            $search->forceMerge();
        }

        $search->refresh();

        // Discarded warm-up. The first query of each size pays for filesystem cache
        // and query-shape compilation, which would otherwise be attributed to size.
        $search->dailyHistogram($query);

        $wall = [];
        $took = [];

        for ($i = 0; $i < $iterations; $i++) {
            $startedAt = microtime(true);
            $result = $search->dailyHistogram($query);

            $wall[] = (microtime(true) - $startedAt) * 1000;
            $took[] = $result->tookMs;
        }

        return [
            'documents' => $search->count(),
            'requested' => $size,
            'index_bytes' => $search instanceof ElasticsearchAdapter ? $search->storeSizeInBytes() : null,
            'index_seconds' => round($import->durationMs / 1000, 1),
            'index_docs_per_second' => $import->documentsPerSecond(),
            'matched' => $search->dailyHistogram($query)->total,
            'iterations' => $iterations,
            'wall_ms' => $this->summarise($wall),
            'engine_took_ms' => $this->summarise($took),
            'failed' => false,
        ];
    }

    /**
     * @param  array<int, float|int>  $samples
     * @return array<string, float>
     */
    private function summarise(array $samples): array
    {
        sort($samples);
        $count = count($samples);

        return [
            'min' => round((float) $samples[0], 2),
            'avg' => round(array_sum($samples) / $count, 2),
            'p95' => round((float) $samples[(int) floor($count * 0.95) === $count ? $count - 1 : (int) floor($count * 0.95)], 2),
            'p99' => round((float) $samples[(int) floor($count * 0.99) === $count ? $count - 1 : (int) floor($count * 0.99)], 2),
            'max' => round((float) $samples[$count - 1], 2),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @param  array<int, string>  $keywords
     */
    private function writeResults(array $results, array $keywords, int $windowDays, int $iterations): void
    {
        $path = base_path((string) $this->option('output'));

        @mkdir(dirname($path), 0o775, true);

        file_put_contents($path, json_encode([
            'generated_at' => Carbon::now()->toIso8601String(),
            'keywords' => $keywords,
            'window_days' => $windowDays,
            'iterations' => $iterations,
            'php' => PHP_VERSION,
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
                $this->humanBytes((int) ($result['index_bytes'] ?? 0)),
                number_format((int) $result['matched']),
                $result['engine_took_ms']['avg'].' ms',
                $result['engine_took_ms']['p95'].' ms',
                $result['wall_ms']['avg'].' ms',
                $result['wall_ms']['p99'].' ms',
            ];
        }

        $this->newLine();
        $this->table(
            ['documents', 'index size', 'matched', 'engine avg', 'engine p95', 'wall avg', 'wall p99'],
            $rows,
        );
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / 1024 ** $power, 1).' '.$units[$power];
    }
}
