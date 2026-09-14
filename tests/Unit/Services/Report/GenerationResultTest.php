<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use Tests\TestCase;
use Illuminate\Support\Carbon;
use App\Services\Report\Data\GenerationResult;
use App\Adapters\Contracts\Data\HistogramBucket;
use App\Adapters\Contracts\Data\HistogramResult;

class GenerationResultTest extends TestCase
{
    public function test_rows_counts_the_histogram_buckets_not_the_matches(): void
    {
        $result = $this->generation(buckets: 7, total: 298_663);

        $this->assertSame(7, $result->rows());
    }

    public function test_it_carries_query_and_wall_clock_time_separately(): void
    {
        $result = $this->generation(queryTookMs: 2, wallClockMs: 9);

        $this->assertSame(2, $result->queryTookMs);
        $this->assertSame(9, $result->wallClockMs);
    }

    /**
     * withFile() folds the export into the total, so a caller reading duration_ms
     * sees end-to-end time while export_ms stays separately attributable.
     */
    public function test_attaching_a_file_adds_the_export_time_to_the_total(): void
    {
        $withFile = $this->generation(wallClockMs: 9)
            ->withFile(path: 'r/20241218-20241221.xlsx', size: 5130, exportMs: 11, peakMemoryBytes: 1024);

        $this->assertSame(20, $withFile->wallClockMs);
        $this->assertSame(11, $withFile->exportMs);
        $this->assertSame('r/20241218-20241221.xlsx', $withFile->filePath);
        $this->assertSame(5130, $withFile->fileSize);
        $this->assertSame(1024, $withFile->peakMemoryBytes);
    }

    public function test_attaching_a_file_leaves_the_query_time_untouched(): void
    {
        $withFile = $this->generation(queryTookMs: 2)->withFile('p', 1, 50, 0);

        $this->assertSame(2, $withFile->queryTookMs);
    }

    public function test_a_freshly_computed_result_has_no_file(): void
    {
        $result = $this->generation();

        $this->assertNull($result->filePath);
        $this->assertNull($result->fileSize);
        $this->assertSame(0, $result->exportMs);
    }

    public function test_the_histogram_survives_attaching_a_file(): void
    {
        $result = $this->generation(buckets: 3, total: 21);

        $this->assertSame(21, $result->withFile('p', 1, 1, 1)->histogram->total);
        $this->assertSame(3, $result->withFile('p', 1, 1, 1)->rows());
    }

    private function generation(
        int $buckets = 1,
        int $total = 1,
        int $queryTookMs = 1,
        int $wallClockMs = 1,
    ): GenerationResult {
        $list = [];

        for ($i = 0; $i < $buckets; $i++) {
            $list[] = new HistogramBucket(Carbon::parse('2024-12-18')->addDays($i), 1);
        }

        return new GenerationResult(
            histogram: new HistogramResult($list, $total, $queryTookMs),
            queryTookMs: $queryTookMs,
            wallClockMs: $wallClockMs,
        );
    }
}
