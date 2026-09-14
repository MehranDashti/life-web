<?php

declare(strict_types=1);

namespace App\Services\Report\Data;

use App\Adapters\Contracts\Data\HistogramResult;

/**
 * What one report execution produced, with its timings split apart.
 *
 * The task asks for query execution time AND report generation time as separate
 * figures, so they are carried separately from the start rather than reconstructed
 * later from one total.
 */
final readonly class GenerationResult
{
    /**
     * Query time and wall-clock are carried separately from the start, because the
     * task asks for both and reconstructing one from the other later loses the
     * distinction.
     */
    public function __construct(
        public HistogramResult $histogram,
        public int $queryTookMs,
        public int $wallClockMs,
        public ?string $filePath = null,
        public ?int $fileSize = null,
        public int $exportMs = 0,
        public int $peakMemoryBytes = 0,
    ) {}

    /**
     * Rows the workbook will hold — one per day in the window, including days with
     * no matches. Not the number of documents matched.
     */
    public function rows(): int
    {
        return count($this->histogram->buckets);
    }

    /**
     * Attach the exported workbook, folding its cost into the total while leaving
     * the query time and the export time separately attributable.
     */
    public function withFile(string $path, int $size, int $exportMs, int $peakMemoryBytes): self
    {
        return new self(
            histogram: $this->histogram,
            queryTookMs: $this->queryTookMs,
            wallClockMs: $this->wallClockMs + $exportMs,
            filePath: $path,
            fileSize: $size,
            exportMs: $exportMs,
            peakMemoryBytes: $peakMemoryBytes,
        );
    }
}
