<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use App\Models\User\User;
use App\Enums\ReportPeriod;
use App\Models\Report\Report;
use Laravel\Passport\Passport;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReportManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    public function test_a_user_can_create_a_report(): void
    {
        $response = $this->postJson('/api/v1/reports', [
            'name' => 'آلودگی تهران',
            'period' => 'daily',
            'keywords' => ['تهران', 'آلودگی'],
        ]);

        $this->assertSuccessEnvelope($response);
        $response->assertJsonPath('data.name', 'آلودگی تهران');
        $response->assertJsonPath('data.period', 'daily');
        $response->assertJsonPath('data.status', 'active');
        $response->assertJsonPath('data.keywords', ['تهران', 'آلودگی']);

        $this->assertDatabaseHas('reports', [
            'name' => 'آلودگی تهران',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_creating_a_report_schedules_its_first_run(): void
    {
        $this->postJson('/api/v1/reports', [
            'name' => 'گزارش', 'period' => 'daily', 'keywords' => ['تهران'],
        ])->assertOk();

        $report = Report::query()->firstOrFail();

        $this->assertNotNull($report->next_run_at);
        $this->assertTrue($report->next_run_at->isFuture());
        $this->assertNull($report->last_run_at);
    }

    /**
     * A client must not be able to create a subscription owned by someone else.
     */
    public function test_a_user_id_in_the_request_body_is_ignored(): void
    {
        $other = User::factory()->create();

        $this->postJson('/api/v1/reports', [
            'name' => 'گزارش',
            'period' => 'daily',
            'keywords' => ['تهران'],
            'user_id' => $other->id,
        ])->assertOk();

        $this->assertSame($this->user->id, Report::query()->firstOrFail()->user_id);
    }

    public function test_duplicate_and_blank_keywords_are_normalised_away(): void
    {
        $this->postJson('/api/v1/reports', [
            'name' => 'گزارش',
            'period' => 'daily',
            'keywords' => ['تهران', 'تهران', '  آلودگی  '],
        ])->assertOk();

        $this->assertSame(['تهران', 'آلودگی'], Report::query()->firstOrFail()->keywords);
    }

    public function test_an_unknown_period_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/reports', [
            'name' => 'گزارش', 'period' => 'hourly', 'keywords' => ['تهران'],
        ]);

        $this->assertFailureEnvelope($response, 422);
        $response->assertJsonStructure(['error' => ['period']]);
    }

    public function test_an_empty_keyword_list_is_rejected(): void
    {
        $this->assertFailureEnvelope(
            $this->postJson('/api/v1/reports', ['name' => 'گزارش', 'period' => 'daily', 'keywords' => []]),
            422,
        );
    }

    /**
     * Every keyword becomes a multi_match clause, so an unbounded list is an
     * unbounded query on every scheduled run.
     */
    public function test_an_excessive_keyword_list_is_rejected(): void
    {
        $this->assertFailureEnvelope(
            $this->postJson('/api/v1/reports', [
                'name' => 'گزارش',
                'period' => 'daily',
                'keywords' => array_map(static fn (int $i): string => "kw{$i}", range(1, 11)),
            ]),
            422,
        );
    }

    public function test_a_user_only_sees_their_own_reports(): void
    {
        Report::factory()->count(2)->create(['user_id' => $this->user->id]);
        Report::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/reports');

        $this->assertSuccessEnvelope($response);
        $response->assertJsonPath('data.pagination.total', 2);
        $this->assertCount(2, $response->json('data.list'));
    }

    public function test_the_list_uses_the_project_wide_envelope(): void
    {
        Report::factory()->create(['user_id' => $this->user->id]);

        $this->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['list', 'pagination' => ['total', 'current', 'page_size']],
            ]);
    }

    /**
     * Client filters narrow within the caller's own rows; no parameter may widen
     * the set to another user's data.
     */
    public function test_a_filter_cannot_widen_the_result_set(): void
    {
        Report::factory()->create(['user_id' => $this->user->id, 'name' => 'mine']);
        Report::factory()->create(['name' => 'theirs']);

        $response = $this->getJson('/api/v1/reports?name=theirs');

        $response->assertOk();
        $response->assertJsonPath('data.pagination.total', 0);
    }

    public function test_the_list_can_be_filtered_by_period_and_status(): void
    {
        Report::factory()->create(['user_id' => $this->user->id, 'period' => ReportPeriod::Daily]);
        Report::factory()->weekly()->create(['user_id' => $this->user->id]);

        $this->getJson('/api/v1/reports?period=weekly')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.list.0.period', 'weekly');
    }

    /**
     * An unbounded page_size is the cheapest way for one request to exhaust a worker.
     */
    public function test_page_size_is_capped(): void
    {
        Report::factory()->count(3)->create(['user_id' => $this->user->id]);

        $this->getJson('/api/v1/reports?page_size=100000')
            ->assertOk()
            ->assertJsonPath('data.pagination.page_size', 200);
    }

    public function test_a_user_can_read_their_own_report(): void
    {
        $report = Report::factory()->create(['user_id' => $this->user->id]);

        $this->getJson("/api/v1/reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $report->id);
    }

    public function test_reading_another_users_report_is_forbidden(): void
    {
        $report = Report::factory()->create();

        $this->assertFailureEnvelope($this->getJson("/api/v1/reports/{$report->id}"), 403);
    }

    public function test_an_unknown_report_is_a_404(): void
    {
        $this->assertFailureEnvelope(
            $this->getJson('/api/v1/reports/01a00000-0000-7000-8000-000000000000'),
            404,
        );
    }
}
