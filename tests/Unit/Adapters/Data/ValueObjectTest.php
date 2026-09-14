<?php

declare(strict_types=1);

namespace Tests\Unit\Adapters\Data;

use Tests\TestCase;
use Illuminate\Support\Carbon;
use App\Adapters\Contracts\Data\BulkResult;
use App\Adapters\Contracts\Data\HistogramQuery;
use App\Adapters\Contracts\Data\HistogramBucket;
use App\Adapters\Contracts\Data\HistogramResult;

/**
 * The typed boundary between the application and the search engine. These objects
 * are what stop raw arrays crossing the seam, so their small behaviours — "did
 * anything fail", "is this empty", "are these two queries the same" — are load
 * bearing for the callers that branch on them.
 */
class ValueObjectTest extends TestCase
{
    public function test_a_bulk_result_reports_no_failures_when_everything_indexed(): void
    {
        $result = new BulkResult(indexed: 100, failed: 0, tookMs: 12);

        $this->assertFalse($result->hasFailures());
        $this->assertSame([], $result->errors);
    }

    /**
     * Elasticsearch reports per-item failures inside a 200, so callers must branch
     * on this rather than on an HTTP status.
     */
    public function test_a_bulk_result_reports_partial_failure(): void
    {
        $result = new BulkResult(indexed: 98, failed: 2, tookMs: 12, errors: ['a: bad', 'b: bad']);

        $this->assertTrue($result->hasFailures());
        $this->assertCount(2, $result->errors);
    }

    public function test_a_histogram_result_knows_when_it_is_empty(): void
    {
        $empty = new HistogramResult(buckets: [], total: 0, tookMs: 1);
        $full = new HistogramResult(
            buckets: [new HistogramBucket(Carbon::parse('2024-12-18'), 3)],
            total: 3,
            tookMs: 1,
        );

        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($full->isEmpty());
    }

    /**
     * A window can contain buckets and still match nothing — every day present with
     * a count of zero. That is a valid, and empty, result.
     */
    public function test_a_result_with_only_zero_buckets_is_empty(): void
    {
        $result = new HistogramResult(
            buckets: [
                new HistogramBucket(Carbon::parse('2024-12-18'), 0),
                new HistogramBucket(Carbon::parse('2024-12-19'), 0),
            ],
            total: 0,
            tookMs: 1,
        );

        $this->assertTrue($result->isEmpty());
        $this->assertCount(2, $result->buckets);
    }

    public function test_identical_queries_share_a_signature(): void
    {
        $this->assertSame($this->histogramQuery()->signature(), $this->histogramQuery()->signature());
    }

    /**
     * The signature deduplicates work, so every field that changes the result must
     * change it — otherwise two different reports would collide.
     */
    public function test_every_field_discriminates_the_signature(): void
    {
        $base = $this->histogramQuery()->signature();

        $this->assertNotSame($base, $this->histogramQuery(keywords: ['اصفهان'])->signature());
        $this->assertNotSame($base, $this->histogramQuery(to: '2024-12-22T00:00:00Z')->signature());
        $this->assertNotSame($base, $this->histogramQuery(timezone: 'UTC')->signature());
        $this->assertNotSame($base, $this->histogramQuery(agencies: ['mehr'])->signature());
        $this->assertNotSame($base, $this->histogramQuery(matchAll: true)->signature());
    }

    public function test_keyword_order_is_significant_to_the_signature(): void
    {
        $this->assertNotSame(
            $this->histogramQuery(keywords: ['a', 'b'])->signature(),
            $this->histogramQuery(keywords: ['b', 'a'])->signature(),
        );
    }

    public function test_a_query_defaults_to_matching_any_keyword(): void
    {
        $this->assertFalse($this->histogramQuery()->matchAllKeywords);
        $this->assertSame([], $this->histogramQuery()->newsAgencyIds);
    }

    /**
     * @param  array<int, string>  $keywords
     * @param  array<int, string>  $agencies
     */
    private function histogramQuery(
        array $keywords = ['تهران'],
        string $to = '2024-12-21T23:59:59Z',
        string $timezone = 'Asia/Tehran',
        array $agencies = [],
        bool $matchAll = false,
    ): HistogramQuery {
        return new HistogramQuery(
            keywords: $keywords,
            from: Carbon::parse('2024-12-18T00:00:00Z'),
            to: Carbon::parse($to),
            timezone: $timezone,
            newsAgencyIds: $agencies,
            matchAllKeywords: $matchAll,
        );
    }
}
