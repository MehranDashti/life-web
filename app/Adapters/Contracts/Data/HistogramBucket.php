<?php

declare(strict_types=1);

namespace App\Adapters\Contracts\Data;

use Illuminate\Support\Carbon;

/**
 * One day of the histogram: the date and how many posts matched on it.
 */
final readonly class HistogramBucket
{
    public function __construct(
        public Carbon $date,
        public int $count,
    ) {}
}
