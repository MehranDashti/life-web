<?php

declare(strict_types=1);

namespace Tests\Unit\Adapters;

use Tests\TestCase;
use Illuminate\Support\Carbon;
use App\Adapters\Fake\FakeSearchAdapter;
use App\Adapters\Contracts\Data\HistogramQuery;

/**
 * Guards the behaviour every consumer of SearchAdapterInterface relies on —
 * so a future real-engine change can be checked against the same expectations.
 */
class FakeSearchAdapterTest extends TestCase
{
    private FakeSearchAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = new FakeSearchAdapter;
        $this->adapter->bulkIndex([
            $this->makePost('1', 'آلودگی هوای تهران', '2024-12-18T08:00:00Z'),
            $this->makePost('2', 'ترافیک تهران', '2024-12-18T20:00:00Z'),
            $this->makePost('3', 'آلودگی در اصفهان', '2024-12-20T09:00:00Z'),
        ]);
    }

    public function test_it_counts_matching_posts_per_day(): void
    {
        $result = $this->adapter->dailyHistogram($this->histogramQuery(['تهران']));

        $this->assertSame(2, $result->total);
        $this->assertSame(2, $result->buckets[0]->count);
    }

    /**
     * A histogram with gaps is wrong — a day with no posts is a zero, not a
     * missing row, otherwise the chart silently compresses time.
     */
    public function test_days_with_no_matches_are_returned_as_zero_buckets(): void
    {
        $result = $this->adapter->dailyHistogram($this->histogramQuery(['تهران']));

        $this->assertCount(4, $result->buckets);
        $this->assertSame(0, $result->buckets[1]->count);
        $this->assertSame(0, $result->buckets[2]->count);
    }

    public function test_multiple_keywords_match_any_by_default(): void
    {
        $result = $this->adapter->dailyHistogram($this->histogramQuery(['تهران', 'اصفهان']));

        $this->assertSame(3, $result->total);
    }

    public function test_multiple_keywords_can_be_required_together(): void
    {
        $result = $this->adapter->dailyHistogram(
            $this->histogramQuery(['تهران', 'آلودگی'], matchAll: true),
        );

        $this->assertSame(1, $result->total);
    }

    public function test_posts_outside_the_range_are_excluded(): void
    {
        $result = $this->adapter->dailyHistogram(new HistogramQuery(
            keywords: ['تهران'],
            from: Carbon::parse('2024-12-19T00:00:00Z'),
            to: Carbon::parse('2024-12-21T23:59:59Z'),
        ));

        $this->assertSame(0, $result->total);
        $this->assertTrue($result->isEmpty());
    }

    public function test_indexing_the_same_id_twice_does_not_duplicate_it(): void
    {
        $this->adapter->bulkIndex([$this->makePost('1', 'آلودگی هوای تهران', '2024-12-18T08:00:00Z')]);

        $this->assertSame(3, $this->adapter->count());
    }

    /**
     * Two identical queries must produce the same signature, and any difference
     * must change it — the signature is what deduplicates report runs.
     */
    public function test_the_query_signature_is_stable_and_discriminating(): void
    {
        $a = $this->histogramQuery(['تهران']);
        $b = $this->histogramQuery(['تهران']);
        $c = $this->histogramQuery(['اصفهان']);

        $this->assertSame($a->signature(), $b->signature());
        $this->assertNotSame($a->signature(), $c->signature());
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function histogramQuery(array $keywords, bool $matchAll = false): HistogramQuery
    {
        return new HistogramQuery(
            keywords: $keywords,
            from: Carbon::parse('2024-12-18T00:00:00Z'),
            to: Carbon::parse('2024-12-21T23:59:59Z'),
            timezone: 'UTC',
            matchAllKeywords: $matchAll,
        );
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
