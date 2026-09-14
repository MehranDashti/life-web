<?php

declare(strict_types=1);

namespace App\Adapters\Fake;

use Illuminate\Support\Carbon;
use App\Adapters\Contracts\Data\BulkResult;
use App\Adapters\Contracts\Data\HistogramQuery;
use App\Adapters\Contracts\Data\HistogramBucket;
use App\Adapters\Contracts\Data\HistogramResult;
use App\Adapters\Contracts\SearchAdapterInterface;

/**
 * In-memory search engine used by the test suite (SEARCH_DRIVER=fake).
 *
 * It keeps unit and feature tests free of infrastructure while still exercising
 * the real contract: documents go in, a per-day histogram comes out, including
 * zero-count days. It is a test double, not a search engine — matching is a
 * substring check, not analysed text search. Anything that depends on the real
 * analysis chain belongs in an integration test against a live Elasticsearch.
 */
final class FakeSearchAdapter implements SearchAdapterInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $documents = [];

    private bool $available = true;

    public function ping(): bool
    {
        return $this->available;
    }

    public function ensureIndex(): void
    {
        // No-op: there is no index to create.
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $documents
     */
    public function bulkIndex(iterable $documents): BulkResult
    {
        $indexed = 0;

        foreach ($documents as $document) {
            $this->documents[(string) $document['id']] = $document;
            $indexed++;
        }

        return new BulkResult(indexed: $indexed, failed: 0, tookMs: 0);
    }

    public function dailyHistogram(HistogramQuery $query): HistogramResult
    {
        $counts = [];
        $total = 0;

        foreach ($this->documents as $document) {
            $publishedAt = Carbon::parse((string) $document['published_at'])->timezone($query->timezone);

            if ($publishedAt->lt($query->from) || $publishedAt->gt($query->to)) {
                continue;
            }

            if (! $this->matchesKeywords($document, $query)) {
                continue;
            }

            $day = $publishedAt->format('Y-m-d');
            $counts[$day] = ($counts[$day] ?? 0) + 1;
            $total++;
        }

        return new HistogramResult(
            buckets: $this->fillRange($query, $counts),
            total: $total,
            tookMs: 0,
        );
    }

    public function count(): int
    {
        return count($this->documents);
    }

    public function refresh(): void
    {
        // No-op: writes are immediately visible in memory.
    }

    public function flush(): void
    {
        $this->documents = [];
    }

    /**
     * Simulate an outage, so the 503 degradation path can be tested.
     */
    public function setAvailable(bool $available): void
    {
        $this->available = $available;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function matchesKeywords(array $document, HistogramQuery $query): bool
    {
        if ($query->keywords === []) {
            return true;
        }

        $haystack = implode(' ', [
            (string) ($document['title'] ?? ''),
            (string) ($document['lead'] ?? ''),
            (string) ($document['content'] ?? ''),
        ]);

        $matches = array_filter(
            $query->keywords,
            static fn (string $keyword): bool => mb_stripos($haystack, $keyword) !== false,
        );

        return $query->matchAllKeywords
            ? count($matches) === count($query->keywords)
            : $matches !== [];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, HistogramBucket>
     */
    private function fillRange(HistogramQuery $query, array $counts): array
    {
        $buckets = [];
        $cursor = $query->from->copy()->timezone($query->timezone)->startOfDay();
        $end = $query->to->copy()->timezone($query->timezone)->startOfDay();

        while ($cursor->lte($end)) {
            $buckets[] = new HistogramBucket(
                date: $cursor->copy(),
                count: $counts[$cursor->format('Y-m-d')] ?? 0,
            );
            $cursor->addDay();
        }

        return $buckets;
    }
}
