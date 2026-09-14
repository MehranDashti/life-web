<?php

declare(strict_types=1);

namespace Tests\Unit\Adapters;

use Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\Support\ThrowingCacheStore;
use App\Adapters\Cached\CorpusVersion;
use App\Adapters\Fake\FakeSearchAdapter;
use Tests\Support\CountingSearchAdapter;
use App\Adapters\Cached\CachedSearchAdapter;
use App\Adapters\Contracts\Data\HistogramQuery;
use App\Adapters\Contracts\Data\HistogramBucket;

/**
 * The cache exists to collapse identical aggregations issued in the same
 * scheduler tick, so every assertion here is about how many calls reach the
 * engine — not about timings, which at 1-2ms per aggregation would prove nothing.
 */
class CachedSearchAdapterTest extends TestCase
{
    private CountingSearchAdapter $engine;

    private FakeSearchAdapter $fake;

    private Repository $cache;

    private CachedSearchAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeSearchAdapter;
        $this->engine = new CountingSearchAdapter($this->fake);
        $this->cache = new Repository(new ArrayStore);
        $this->adapter = $this->wrap($this->cache);

        $this->adapter->bulkIndex([
            $this->makePost('1', 'آلودگی هوای تهران', '2024-12-18T08:00:00Z'),
            $this->makePost('2', 'ترافیک تهران', '2024-12-19T08:00:00Z'),
        ]);
    }

    public function test_an_identical_query_reaches_the_engine_once(): void
    {
        $this->adapter->dailyHistogram($this->histogramQuery());
        $this->adapter->dailyHistogram($this->histogramQuery());
        $this->adapter->dailyHistogram($this->histogramQuery());

        $this->assertSame(1, $this->engine->histogramCalls);
    }

    public function test_a_different_query_is_a_separate_entry(): void
    {
        $this->adapter->dailyHistogram($this->histogramQuery(['تهران']));
        $this->adapter->dailyHistogram($this->histogramQuery(['اصفهان']));

        $this->assertSame(2, $this->engine->histogramCalls);
    }

    public function test_a_cached_result_equals_the_uncached_one(): void
    {
        $fresh = $this->adapter->dailyHistogram($this->histogramQuery());
        $cached = $this->adapter->dailyHistogram($this->histogramQuery());

        $this->assertSame($fresh->total, $cached->total);
        $this->assertCount(count($fresh->buckets), $cached->buckets);
        $this->assertSame(
            array_map(static fn (HistogramBucket $b): string => $b->date->toIso8601String(), $fresh->buckets),
            array_map(static fn (HistogramBucket $b): string => $b->date->toIso8601String(), $cached->buckets),
        );
        $this->assertSame(
            array_map(static fn (HistogramBucket $b): int => $b->count, $fresh->buckets),
            array_map(static fn (HistogramBucket $b): int => $b->count, $cached->buckets),
        );
    }

    /**
     * No engine time was spent, so reporting the original figure would be a lie —
     * and a zero makes the collapse visible in report_runs.query_took_ms.
     */
    public function test_a_hit_reports_no_engine_time(): void
    {
        $this->adapter->dailyHistogram($this->histogramQuery());

        $this->assertSame(0, $this->adapter->dailyHistogram($this->histogramQuery())->tookMs);
    }

    /**
     * A JsonResource collection cached earlier in this project came back as
     * __PHP_Incomplete_Class and served garbage. Primitives make a class rename a
     * non-event.
     */
    public function test_the_cached_payload_contains_no_serialised_object(): void
    {
        $this->adapter->dailyHistogram($this->histogramQuery());

        $stored = $this->storedHistogram();

        $this->assertIsArray($stored);
        $this->assertArrayHasKey('buckets', $stored);
        $this->assertArrayHasKey('total', $stored);
        $this->assertStringNotContainsString('__PHP_Incomplete_Class', serialize($stored));
        $this->assertStringNotContainsString('HistogramResult', serialize($stored));

        foreach ($stored['buckets'] as $bucket) {
            $this->assertIsString($bucket['date']);
            $this->assertIsInt($bucket['count']);
        }
    }

    public function test_indexing_invalidates_every_cached_result(): void
    {
        $before = $this->adapter->dailyHistogram($this->histogramQuery())->total;

        $this->adapter->bulkIndex([$this->makePost('3', 'تهران دوباره', '2024-12-18T10:00:00Z')]);

        $after = $this->adapter->dailyHistogram($this->histogramQuery());

        $this->assertSame(2, $this->engine->histogramCalls, 'The write must force a miss.');
        $this->assertSame($before + 1, $after->total);
    }

    public function test_flushing_invalidates_every_cached_result(): void
    {
        $this->adapter->dailyHistogram($this->histogramQuery());
        $this->adapter->flush();

        $this->assertSame(0, $this->adapter->dailyHistogram($this->histogramQuery())->total);
        $this->assertSame(2, $this->engine->histogramCalls);
    }

    public function test_creating_the_index_invalidates(): void
    {
        $this->adapter->dailyHistogram($this->histogramQuery());
        $this->adapter->ensureIndex();
        $this->adapter->dailyHistogram($this->histogramQuery());

        $this->assertSame(2, $this->engine->histogramCalls);
    }

    public function test_the_document_count_is_cached_and_invalidated(): void
    {
        $this->adapter->count();
        $this->adapter->count();
        $this->assertSame(1, $this->engine->countCalls);

        $this->adapter->bulkIndex([$this->makePost('9', 'x', '2024-12-18T08:00:00Z')]);

        $this->adapter->count();
        $this->assertSame(2, $this->engine->countCalls);
    }

    /**
     * A cached verdict would let the health endpoint report a cluster as
     * reachable minutes after it stopped being so.
     */
    public function test_liveness_is_never_cached(): void
    {
        $this->adapter->ping();
        $this->adapter->ping();
        $this->adapter->ping();

        $this->assertSame(3, $this->engine->pingCalls);
    }

    /**
     * A cache outage must slow the system down, never break it.
     */
    public function test_a_failing_cache_falls_through_to_the_engine(): void
    {
        $adapter = $this->wrap(new Repository(new ThrowingCacheStore));

        $first = $adapter->dailyHistogram($this->histogramQuery());
        $second = $adapter->dailyHistogram($this->histogramQuery());

        $this->assertSame($first->total, $second->total);
        $this->assertSame(2, $this->engine->histogramCalls, 'Every call must reach the engine.');
    }

    public function test_a_failing_cache_does_not_break_indexing(): void
    {
        $adapter = $this->wrap(new Repository(new ThrowingCacheStore));

        $result = $adapter->bulkIndex([$this->makePost('4', 'x', '2024-12-18T08:00:00Z')]);

        $this->assertSame(1, $result->indexed);
    }

    public function test_inner_exposes_the_wrapped_adapter_for_callers_that_must_reach_the_engine(): void
    {
        $this->assertSame($this->engine, $this->adapter->inner());
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function histogramQuery(array $keywords = ['تهران']): HistogramQuery
    {
        return new HistogramQuery(
            keywords: $keywords,
            from: Carbon::parse('2024-12-18T00:00:00Z'),
            to: Carbon::parse('2024-12-20T23:59:59Z'),
            timezone: 'UTC',
        );
    }

    private function wrap(Repository $cache): CachedSearchAdapter
    {
        return new CachedSearchAdapter(
            inner: $this->engine,
            cache: $cache,
            version: new CorpusVersion($cache),
            ttl: 900,
            countTtl: 60,
        );
    }

    /**
     * The stored payload for the default query, found by asking the same version
     * source the adapter uses rather than guessing at the key.
     *
     * @return array<string, mixed>|null
     */
    private function storedHistogram(): ?array
    {
        $key = 'search:hist:'
            .(new CorpusVersion($this->cache))->current()
            .':'.$this->histogramQuery()->signature();

        $value = $this->cache->get($key);

        return is_array($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function makePost(string $id, string $title, string $publishedAt): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'lead' => '',
            'content' => '',
            'published_at' => $publishedAt,
        ];
    }
}
