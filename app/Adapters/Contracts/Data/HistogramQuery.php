<?php

declare(strict_types=1);

namespace App\Adapters\Contracts\Data;

use Illuminate\Support\Carbon;

/**
 * The input to a daily post-count histogram.
 *
 * A value object rather than an array so the search boundary is typed end to end
 * and PHPStan can check every call site.
 */
final readonly class HistogramQuery
{
    /**
     * @param  array<int, string>  $keywords  matched across title, lead and content
     * @param  array<int, string>  $newsAgencyIds  optional narrowing filter
     */
    public function __construct(
        public array $keywords,
        public Carbon $from,
        public Carbon $to,
        public string $timezone = 'Asia/Tehran',
        public array $newsAgencyIds = [],
        public bool $matchAllKeywords = false,
    ) {}

    /**
     * A stable fingerprint of this query, for cache keys and run deduplication.
     */
    public function signature(): string
    {
        return md5(serialize([
            $this->keywords,
            $this->from->toIso8601String(),
            $this->to->toIso8601String(),
            $this->timezone,
            $this->newsAgencyIds,
            $this->matchAllKeywords,
        ]));
    }
}
