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
    public function __construct(
        public HistogramResult $histogram,
        public int $queryTookMs,
        public int $wallClockMs,
        public ?string $filePath = null,
        public ?int $fileSize = null,
        public int $exportMs = 0,
        public int $peakMemoryBytes = 0,
    ) {}

    public function rows(): int
    {
        return count($this->histogram->buckets);
    }

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
