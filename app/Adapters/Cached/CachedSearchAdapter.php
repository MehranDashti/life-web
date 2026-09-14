<?php

declare(strict_types=1);

namespace App\Adapters\Cached;

use Throwable;
use Illuminate\Support\Carbon;
use Illuminate\Contracts\Cache\Repository;
use App\Adapters\Contracts\Data\BulkResult;
use App\Adapters\Contracts\Data\HistogramQuery;
use App\Adapters\Contracts\Data\HistogramBucket;
use App\Adapters\Contracts\Data\HistogramResult;
use App\Adapters\Contracts\SearchAdapterInterface;

/**
 * Caches search reads in front of another adapter.
 *
 * A decorator rather than caching inside ElasticsearchAdapter: the engine class
 * stays about Elasticsearch, every caller keeps the same contract, and hit/miss
 * behaviour can be proven by wrapping the in-memory double with a counting spy —
 * no Redis and no cluster required.
 *
 * What this is for: search is only reached during report generation, so load
 * concentrates on the scheduler tick, where many due reports issue IDENTICAL
 * aggregations because their owners track overlapping keywords over the same
 * window. This collapses those into one engine call.
 *
 * What it is not for: latency. The project's own benchmark measures the
 * aggregation at 1-2ms flat from 10k to 1M documents.
 */
final readonly class CachedSearchAdapter implements SearchAdapterInterface
{
    public function __construct(
        private SearchAdapterInterface $inner,
        private Repository $cache,
        private CorpusVersion $version,
        private int $ttl,
        private int $countTtl,
    ) {}

    /**
     * The adapter this one wraps.
     *
     * For callers that must measure or address the engine itself rather than the
     * cached view of it — the benchmarks, which exist to characterise
     * Elasticsearch and would otherwise report the cache's timings as the
     * engine's.
     */
    public function inner(): SearchAdapterInterface
    {
        return $this->inner;
    }

    /**
     * Liveness is never cached. A cached verdict would let the health endpoint
     * report a cluster as reachable minutes after it stopped being so.
     */
    public function ping(): bool
    {
        return $this->inner->ping();
    }

    /**
     * Creating the index changes what queries can return, so it invalidates.
     */
    public function ensureIndex(): void
    {
        $this->inner->ensureIndex();
        $this->version->bump();
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $documents
     */
    public function bulkIndex(iterable $documents): BulkResult
    {
        $result = $this->inner->bulkIndex($documents);
        $this->version->bump();

        return $result;
    }

    /**
     * The whole point of this class: identical queries in the same tick cost one
     * aggregation instead of one each.
     */
    public function dailyHistogram(HistogramQuery $query): HistogramResult
    {
        $key = 'search:hist:'.$this->version->current().':'.$query->signature();

        $cached = $this->read($key);

        if (is_array($cached)) {
            return $this->rehydrate($cached);
        }

        $result = $this->inner->dailyHistogram($query);

        $this->write($key, $this->dehydrate($result), $this->ttl);

        return $result;
    }

    public function count(): int
    {
        $key = 'search:count:'.$this->version->current();

        $cached = $this->read($key);

        if (is_int($cached)) {
            return $cached;
        }

        $count = $this->inner->count();

        $this->write($key, $count, $this->countTtl);

        return $count;
    }

    /**
     * Refreshing only makes already-indexed documents visible; the version was
     * bumped by the write that produced them.
     */
    public function refresh(): void
    {
        $this->inner->refresh();
    }

    public function flush(): void
    {
        $this->inner->flush();
        $this->version->bump();
    }

    /**
     * A cache read must never be the reason a search fails, so any store failure
     * is treated as a miss and answered by the engine.
     */
    private function read(string $key): mixed
    {
        try {
            return $this->cache->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function write(string $key, mixed $value, int $ttl): void
    {
        try {
            $this->cache->put($key, $value, $ttl);
        } catch (Throwable) {
            // Unwritable cache degrades to no cache, never to a failed request.
        }
    }

    /**
     * Primitives only — never a serialised object graph.
     *
     * This project has already shipped one bug from caching a rich object: a
     * JsonResource collection came back from Redis as __PHP_Incomplete_Class and
     * the list endpoint served garbage. A scalar payload makes a class rename or
     * an added property a non-event rather than an incident.
     *
     * @return array{buckets: array<int, array{date: string, count: int}>, total: int}
     */
    private function dehydrate(HistogramResult $result): array
    {
        $buckets = [];

        foreach ($result->buckets as $bucket) {
            $buckets[] = [
                'date' => $bucket->date->toIso8601String(),
                'count' => $bucket->count,
            ];
        }

        return ['buckets' => $buckets, 'total' => $result->total];
    }

    /**
     * Query time is reported as zero on a hit, because none was spent. That is
     * truthful, and it makes the collapse visible in report_runs.query_took_ms.
     *
     * @param  array<string, mixed>  $payload
     */
    private function rehydrate(array $payload): HistogramResult
    {
        $buckets = [];

        foreach ($payload['buckets'] ?? [] as $bucket) {
            $buckets[] = new HistogramBucket(
                Carbon::parse((string) $bucket['date']),
                (int) $bucket['count'],
            );
        }

        return new HistogramResult($buckets, (int) ($payload['total'] ?? 0), tookMs: 0);
    }
}
