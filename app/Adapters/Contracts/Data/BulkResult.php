<?php

declare(strict_types=1);

namespace App\Adapters\Contracts\Data;

/**
 * The outcome of one bulk-indexing chunk. Partial failure is normal in bulk
 * indexing — Elasticsearch reports it per item, not as a request-level error —
 * so callers must inspect `$failed` rather than assume success.
 */
final readonly class BulkResult
{
    /**
     * @param  array<int, string>  $errors  one message per failed item, capped by the adapter
     */
    public function __construct(
        public int $indexed,
        public int $failed,
        public int $tookMs,
        public array $errors = [],
    ) {}

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
