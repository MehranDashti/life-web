<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use Tests\TestCase;
use App\Services\Search\Data\ImportResult;

class ImportResultTest extends TestCase
{
    public function test_it_totals_indexed_and_failed_documents(): void
    {
        $result = new ImportResult(indexed: 980, failed: 20, chunks: 1, durationMs: 1000);

        $this->assertSame(1000, $result->total());
        $this->assertTrue($result->hasFailures());
    }

    public function test_a_clean_import_reports_no_failures(): void
    {
        $result = new ImportResult(indexed: 21, failed: 0, chunks: 1, durationMs: 40);

        $this->assertFalse($result->hasFailures());
        $this->assertSame(21, $result->total());
    }

    public function test_it_computes_an_indexing_rate(): void
    {
        $result = new ImportResult(indexed: 5000, failed: 0, chunks: 5, durationMs: 1000);

        $this->assertSame(5000.0, $result->documentsPerSecond());
    }

    /**
     * A sub-millisecond import must not divide by zero.
     */
    public function test_a_zero_duration_reports_a_zero_rate_rather_than_dividing_by_zero(): void
    {
        $result = new ImportResult(indexed: 10, failed: 0, chunks: 1, durationMs: 0);

        $this->assertSame(0.0, $result->documentsPerSecond());
    }

    public function test_the_rate_counts_only_successfully_indexed_documents(): void
    {
        $result = new ImportResult(indexed: 500, failed: 500, chunks: 1, durationMs: 1000);

        $this->assertSame(500.0, $result->documentsPerSecond());
    }
}
