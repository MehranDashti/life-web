<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Adapters\Contracts\Data\BulkResult;
use App\Adapters\Contracts\Data\HistogramQuery;
use App\Adapters\Contracts\Data\HistogramResult;
use App\Adapters\Contracts\SearchAdapterInterface;

/**
 * Counts how many calls actually reach the engine.
 *
 * The objective of the search cache is "N due reports cost one aggregation", so
 * the assertion has to be about call counts rather than timings — a millisecond
 * measurement would prove nothing at this scale.
 */
final class CountingSearchAdapter implements SearchAdapterInterface
{
    public int $histogramCalls = 0;

    public int $countCalls = 0;

    public int $pingCalls = 0;

    public function __construct(private readonly SearchAdapterInterface $inner) {}

    public function ping(): bool
    {
        $this->pingCalls++;

        return $this->inner->ping();
    }

    public function ensureIndex(): void
    {
        $this->inner->ensureIndex();
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $documents
     */
    public function bulkIndex(iterable $documents): BulkResult
    {
        return $this->inner->bulkIndex($documents);
    }

    public function dailyHistogram(HistogramQuery $query): HistogramResult
    {
        $this->histogramCalls++;

        return $this->inner->dailyHistogram($query);
    }

    public function count(): int
    {
        $this->countCalls++;

        return $this->inner->count();
    }

    public function refresh(): void
    {
        $this->inner->refresh();
    }

    public function flush(): void
    {
        $this->inner->flush();
    }
}
