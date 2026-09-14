<?php

declare(strict_types=1);

namespace App\DTO\Report;

use App\Enums\ReportPeriod;
use App\Enums\ReportStatus;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use App\DTO\Contracts\ToArrayDTOInterface;
use Illuminate\Foundation\Http\FormRequest;
use App\DTO\Contracts\FromRequestDTOInterface;

/**
 * The payload for creating a report subscription.
 *
 * `userId` is stamped from the authenticated caller inside fromRequest(), never
 * read from the request body — otherwise a client could create a subscription
 * owned by someone else.
 */
final readonly class CreateReportDTO implements FromRequestDTOInterface, ToArrayDTOInterface
{
    /**
     * @param  array<int, string>  $keywords
     * @param  array<int, string>|null  $newsAgencyIds
     */
    public function __construct(
        public string $userId = '',
        public string $name = '',
        public ReportPeriod $period = ReportPeriod::Daily,
        public array $keywords = [],
        public ?array $newsAgencyIds = null,
        public bool $matchAllKeywords = false,
    ) {}

    public function fromRequest(FormRequest $request): static
    {
        $agencies = $request->input('news_agency_ids');

        return new self(
            userId: (string) $request->user()?->getAuthIdentifier(),
            name: trim((string) $request->input('name')),
            period: ReportPeriod::from((string) $request->input('period')),
            keywords: $this->normaliseKeywords((array) $request->input('keywords', [])),
            newsAgencyIds: is_array($agencies) && $agencies !== [] ? array_values(array_map(strval(...), $agencies)) : null,
            matchAllKeywords: $request->boolean('match_all_keywords'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Model $model = null): array
    {
        return [
            'user_id' => $this->userId,
            'name' => $this->name,
            'period' => $this->period,
            'keywords' => $this->keywords,
            'news_agency_ids' => $this->newsAgencyIds,
            'match_all_keywords' => $this->matchAllKeywords,
            'status' => ReportStatus::Active,
            'next_run_at' => $this->period->firstRunAt(Carbon::now()),
            'consecutive_failures' => 0,
        ];
    }

    /**
     * Trim, drop blanks and de-duplicate. A keyword list containing the same term
     * twice would build a larger Elasticsearch query for identical results.
     *
     * @param  array<int|string, mixed>  $keywords
     * @return array<int, string>
     */
    private function normaliseKeywords(array $keywords): array
    {
        $cleaned = array_map(static fn (mixed $keyword): string => trim((string) $keyword), $keywords);
        $cleaned = array_filter($cleaned, static fn (string $keyword): bool => $keyword !== '');

        return array_values(array_unique($cleaned));
    }
}
