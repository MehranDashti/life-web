<?php

declare(strict_types=1);

namespace Tests\Support;

use Throwable;
use RuntimeException;
use App\Adapters\Contracts\Data\BulkResult;
use App\Adapters\Contracts\Data\HistogramQuery;
use App\Adapters\Contracts\Data\HistogramResult;
use App\Adapters\Contracts\SearchAdapterInterface;

/**
 * A search adapter that always fails, for exercising the degradation paths:
 * a failed run, an unadvanced schedule, and the pause-after-repeated-failures rule.
 */
final readonly class ThrowingSearchAdapter implements SearchAdapterInterface
{
    public function __construct(private Throwable $failure = new RuntimeException('cluster unavailable')) {}

    public function ping(): bool
    {
        return false;
    }

    public function ensureIndex(): void {}

    public function bulkIndex(iterable $documents): BulkResult
    {
        throw $this->failure;
    }

    public function dailyHistogram(HistogramQuery $query): HistogramResult
    {
        throw $this->failure;
    }

    public function count(): int
    {
        return 0;
    }

    public function refresh(): void {}

    public function flush(): void {}
}
