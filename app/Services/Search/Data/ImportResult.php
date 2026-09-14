<?php

declare(strict_types=1);

namespace App\Services\Search\Data;

/**
 * The aggregate outcome of an import: every chunk's BulkResult rolled up.
 */
final readonly class ImportResult
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public int $indexed,
        public int $failed,
        public int $chunks,
        public int $durationMs,
        public array $errors = [],
    ) {}

    /**
     * Whether any document was rejected. Bulk indexing reports per-item failures
     * inside an otherwise successful response, so callers must check this rather
     * than an HTTP status.
     */
    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    /**
     * Documents attempted, successful or not.
     */
    public function total(): int
    {
        return $this->indexed + $this->failed;
    }

    /**
     * Indexing throughput, counting only documents that actually landed. Guards
     * against dividing by zero on a sub-millisecond import.
     */
    public function documentsPerSecond(): float
    {
        return $this->durationMs > 0
            ? round($this->indexed / ($this->durationMs / 1000), 1)
            : 0.0;
    }
}
