<?php

declare(strict_types=1);

namespace App\Adapters\Contracts\Data;

/**
 * The output of a daily histogram: one bucket per day in the requested range
 * (including zero-count days), plus the totals used to report query performance.
 */
final readonly class HistogramResult
{
    /**
     * @param  array<int, HistogramBucket>  $buckets
     * @param  int  $tookMs  Elasticsearch's own reported query time
     */
    public function __construct(
        public array $buckets,
        public int $total,
        public int $tookMs,
    ) {}

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }
}
