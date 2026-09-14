<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Throwable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use Elastic\Elasticsearch\Client;
use App\Services\Search\PostIndexService;
use App\Adapters\Cached\CachedSearchAdapter;
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
        {--output=loadtest/results/bench-search.json : Where to write the raw results}
        {--herd= : Instead of sweeping, issue N identical queries and report how many reached the engine}';

    protected $description = 'Sweep the corpus and measure aggregation time at each size';

    public function handle(PostIndexService $indexer, SearchAdapterInterface $search): int
    {
        $search = $this->engine($search);

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

        if ($this->option('herd') !== null) {
            return $this->measureHerd($search, $query, max(1, (int) $this->option('herd')));
        }

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
     * Measure the search cache in the only terms that matter for it.
     *
     * Latency is the wrong metric: the aggregation is 1-2ms flat from 10k to 1M
     * documents, so a cache cannot make it meaningfully faster. What it does is
     * stop N due reports asking the same question N times. This reads
     * Elasticsearch's own query_total counter across N identical queries, so the
     * figure reported is aggregations the engine never had to run.
     */
    private function measureHerd(SearchAdapterInterface $search, HistogramQuery $query, int $times): int
    {
        $before = $this->engineQueryTotal();

        for ($i = 0; $i < $times; $i++) {
            $search->dailyHistogram($query);
        }

        $reached = $this->engineQueryTotal() - $before;

        $this->newLine();
        $this->components->twoColumnDetail('Identical queries issued', (string) $times);
        $this->components->twoColumnDetail('Aggregations reaching the engine', (string) $reached);
        $this->components->twoColumnDetail('Avoided', (string) max(0, $times - $reached));
        $this->components->twoColumnDetail(
            'Search cache',
            config('search.cache.enabled') ? 'enabled' : 'disabled',
        );

        $this->writeResults([[
            'mode' => 'herd',
            'queries_issued' => $times,
            'aggregations_reaching_engine' => $reached,
            'cache_enabled' => (bool) config('search.cache.enabled'),
            'failed' => false,
        ]], [], 0, $times);

        return self::SUCCESS;
    }

    /**
     * Elasticsearch's own counter of executed queries, which cannot be fooled by
     * anything happening on the application side.
     */
    private function engineQueryTotal(): int
    {
        try {
            $client = app(Client::class);
            $stats = $client->indices()->stats([
                'index' => (string) config('search.elasticsearch.index_pattern'),
                'metric' => 'search',
                'ignore_unavailable' => true,
            ])->asArray();

            return (int) Arr::get($stats, '_all.total.search.query_total', 0);
        } catch (Throwable) {
            return 0;
        }
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
