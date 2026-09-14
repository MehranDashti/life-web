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

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    public function total(): int
    {
        return $this->indexed + $this->failed;
    }

    public function documentsPerSecond(): float
    {
        return $this->durationMs > 0
            ? round($this->indexed / ($this->durationMs / 1000), 1)
            : 0.0;
    }
}
