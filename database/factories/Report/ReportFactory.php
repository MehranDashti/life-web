<?php

declare(strict_types=1);

namespace Database\Factories\Report;

use App\Models\User\User;
use App\Enums\ReportPeriod;
use App\Enums\ReportStatus;
use App\Models\Report\Report;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        $period = ReportPeriod::Daily;

        return [
            'user_id' => User::factory(),
            'name' => 'گزارش '.fake()->words(2, true),
            'period' => $period,
            'keywords' => ['تهران'],
            'news_agency_ids' => null,
            'match_all_keywords' => false,
            'status' => ReportStatus::Active,
            'last_run_at' => null,
            'next_run_at' => $period->firstRunAt(Carbon::now()),
            'consecutive_failures' => 0,
        ];
    }

    public function weekly(): self
    {
        return $this->state(fn (): array => [
            'period' => ReportPeriod::Weekly,
            'next_run_at' => ReportPeriod::Weekly->firstRunAt(Carbon::now()),
        ]);
    }

    public function paused(): self
    {
        return $this->state(fn (): array => ['status' => ReportStatus::Paused]);
    }

    /**
     * Due right now — the state the dispatcher is supposed to pick up.
     */
    public function due(): self
    {
        return $this->state(fn (): array => ['next_run_at' => Carbon::now()->subMinute()]);
    }

    public function notYetDue(): self
    {
        return $this->state(fn (): array => ['next_run_at' => Carbon::now()->addDay()]);
    }

    /**
     * @param  array<int, string>  $keywords
     */
    public function withKeywords(array $keywords, bool $matchAll = false): self
    {
        return $this->state(fn (): array => [
            'keywords' => $keywords,
            'match_all_keywords' => $matchAll,
        ]);
    }
}
