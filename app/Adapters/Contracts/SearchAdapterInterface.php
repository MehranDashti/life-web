<?php

declare(strict_types=1);

namespace App\Adapters\Contracts;

use App\Adapters\Contracts\Data\BulkResult;
use App\Adapters\Contracts\Data\HistogramQuery;
use App\Adapters\Contracts\Data\HistogramResult;

/**
 * The only way application code may reach the search engine.
 *
 * No service, job, or controller may touch Elastic\Elasticsearch\Client directly.
 * Keeping the seam here is what makes the engine swappable, the unit tests
 * infrastructure-free (see FakeSearchAdapter), and the query shapes reviewable
 * in one place.
 */
interface SearchAdapterInterface
{
    /**
     * Liveness check for the health endpoint. Must never throw — an unreachable
     * engine reports false so the API can degrade instead of failing the request.
     */
    public function ping(): bool;

    /**
     * Create or update the index template and the write alias. Idempotent.
     */
    public function ensureIndex(): void;

    /**
     * Index a chunk of documents.
     *
     * @param  iterable<int, array<string, mixed>>  $documents  each must carry an `id`
     */
    public function bulkIndex(iterable $documents): BulkResult;

    /**
     * Count matching posts per day.
     *
     * Implementations MUST answer this with a `size: 0` date_histogram
     * aggregation. Fetching documents in order to count them does not scale and
     * is the single most important constraint in this contract.
     */
    public function dailyHistogram(HistogramQuery $query): HistogramResult;

    /**
     * Total number of indexed documents — used by the benchmark harness to label
     * results by data volume.
     */
    public function count(): int;

    /**
     * Make everything indexed so far visible to search.
     *
     * Bulk indexing runs with refresh disabled, so an importer refreshes once at
     * the end rather than once per chunk. Without this on the contract, callers
     * would have to reach around the adapter to the engine client.
     */
    public function refresh(): void;

    /**
     * Drop every index in the family. Destructive; exists for the benchmark
     * sweep, which measures the same query against successive corpus sizes.
     */
    public function flush(): void;
}
