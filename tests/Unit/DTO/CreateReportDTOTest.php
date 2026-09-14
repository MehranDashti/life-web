<?php

declare(strict_types=1);

namespace Tests\Unit\DTO;

use Tests\TestCase;
use App\Models\User\User;
use App\Enums\ReportPeriod;
use App\Enums\ReportStatus;
use Illuminate\Support\Carbon;
use App\DTO\Report\CreateReportDTO;
use App\Http\Requests\Report\CreateReportRequest;

/**
 * The DTO is the boundary between a validated request and what gets persisted.
 * Two things matter here and are easy to get wrong: keyword hygiene, and the fact
 * that ownership comes from the authenticated user rather than from input.
 */
class CreateReportDTOTest extends TestCase
{
    public function test_it_builds_from_a_request(): void
    {
        $dto = $this->fromInput([
            'name' => 'آلودگی تهران',
            'period' => 'daily',
            'keywords' => ['تهران', 'آلودگی'],
        ]);

        $this->assertSame('آلودگی تهران', $dto->name);
        $this->assertSame(ReportPeriod::Daily, $dto->period);
        $this->assertSame(['تهران', 'آلودگی'], $dto->keywords);
        $this->assertFalse($dto->matchAllKeywords);
        $this->assertNull($dto->newsAgencyIds);
    }

    public function test_the_owner_comes_from_the_authenticated_user_not_the_payload(): void
    {
        $user = new User(['username' => 'demo']);
        $user->id = '01a00000-0000-7000-8000-000000000001';

        $dto = $this->fromInput([
            'name' => 'r',
            'period' => 'daily',
            'keywords' => ['تهران'],
            'user_id' => '01a00000-0000-7000-8000-00000000ffff',
        ], $user);

        $this->assertSame($user->id, $dto->userId);
    }

    public function test_keywords_are_trimmed(): void
    {
        $dto = $this->fromInput([
            'name' => 'r', 'period' => 'daily', 'keywords' => ['  تهران  ', "\tآلودگی\n"],
        ]);

        $this->assertSame(['تهران', 'آلودگی'], $dto->keywords);
    }

    /**
     * A repeated keyword would build a larger Elasticsearch query for identical
     * results, on every scheduled run.
     */
    public function test_duplicate_keywords_are_removed(): void
    {
        $dto = $this->fromInput([
            'name' => 'r', 'period' => 'daily', 'keywords' => ['تهران', 'تهران', ' تهران '],
        ]);

        $this->assertSame(['تهران'], $dto->keywords);
    }

    public function test_blank_keywords_are_dropped(): void
    {
        $dto = $this->fromInput([
            'name' => 'r', 'period' => 'daily', 'keywords' => ['تهران', '   ', ''],
        ]);

        $this->assertSame(['تهران'], $dto->keywords);
    }

    /**
     * array_unique and array_filter both preserve keys; the result must still be a
     * list or it serialises to a JSON object instead of an array.
     */
    public function test_the_keyword_list_stays_a_json_array_after_filtering(): void
    {
        $dto = $this->fromInput([
            'name' => 'r', 'period' => 'daily', 'keywords' => ['a', 'a', 'b', '', 'c'],
        ]);

        $this->assertIsList($dto->keywords);
        $this->assertSame('["a","b","c"]', json_encode($dto->keywords));
    }

    public function test_the_report_name_is_trimmed(): void
    {
        $this->assertSame('گزارش', $this->fromInput([
            'name' => '  گزارش  ', 'period' => 'daily', 'keywords' => ['تهران'],
        ])->name);
    }

    public function test_an_empty_agency_list_becomes_null_rather_than_an_empty_array(): void
    {
        $dto = $this->fromInput([
            'name' => 'r', 'period' => 'daily', 'keywords' => ['تهران'], 'news_agency_ids' => [],
        ]);

        $this->assertNull($dto->newsAgencyIds);
    }

    public function test_agency_ids_are_cast_to_strings_and_relisted(): void
    {
        $dto = $this->fromInput([
            'name' => 'r', 'period' => 'daily', 'keywords' => ['تهران'],
            'news_agency_ids' => ['mehr', 123],
        ]);

        $this->assertSame(['mehr', '123'], $dto->newsAgencyIds);
        $this->assertIsList($dto->newsAgencyIds);
    }

    public function test_match_all_keywords_is_read_as_a_boolean(): void
    {
        foreach (['1', 1, true, 'true'] as $truthy) {
            $this->assertTrue($this->fromInput([
                'name' => 'r', 'period' => 'daily', 'keywords' => ['a'], 'match_all_keywords' => $truthy,
            ])->matchAllKeywords);
        }

        foreach (['0', 0, false, 'false'] as $falsy) {
            $this->assertFalse($this->fromInput([
                'name' => 'r', 'period' => 'daily', 'keywords' => ['a'], 'match_all_keywords' => $falsy,
            ])->matchAllKeywords);
        }
    }

    public function test_to_array_produces_the_persistence_payload(): void
    {
        $payload = $this->fromInput([
            'name' => 'گزارش', 'period' => 'weekly', 'keywords' => ['تهران'],
        ])->toArray();

        $this->assertSame('گزارش', $payload['name']);
        $this->assertSame(ReportPeriod::Weekly, $payload['period']);
        $this->assertSame(ReportStatus::Active, $payload['status']);
        $this->assertSame(0, $payload['consecutive_failures']);
        $this->assertInstanceOf(Carbon::class, $payload['next_run_at']);
    }

    /**
     * A new report must not be immediately due, or it would fire on the next tick
     * with a window it was never subscribed for.
     */
    public function test_a_new_report_is_scheduled_in_the_future(): void
    {
        $payload = $this->fromInput([
            'name' => 'r', 'period' => 'daily', 'keywords' => ['تهران'],
        ])->toArray();

        $this->assertTrue($payload['next_run_at']->isFuture());
        $this->assertSame('UTC', $payload['next_run_at']->getTimezone()->getName());
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function fromInput(array $input, ?User $user = null): CreateReportDTO
    {
        $request = CreateReportRequest::create('/api/v1/reports', 'POST', $input);
        $request->setUserResolver(fn (): User => $user ?? $this->stubUser());

        return (new CreateReportDTO)->fromRequest($request);
    }

    private function stubUser(): User
    {
        $user = new User(['username' => 'demo']);
        $user->id = '01a00000-0000-7000-8000-000000000001';

        return $user;
    }
}
