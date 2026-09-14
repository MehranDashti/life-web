<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use App\Models\User\User;
use App\Models\Report\Report;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReportRunTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('reports');
        $this->fakeSearch();

        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
        $this->report = Report::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_a_user_can_list_the_runs_of_their_report(): void
    {
        $this->report->runs()->createMany([
            $this->runAttributes('2024-12-18'),
            $this->runAttributes('2024-12-19'),
        ]);

        $response = $this->getJson("/api/v1/reports/{$this->report->id}/runs");

        $this->assertSuccessEnvelope($response);
        $response->assertJsonPath('data.pagination.total', 2);
        $response->assertJsonStructure([
            'data' => ['list' => [['id', 'status', 'period_start', 'period_end', 'rows', 'total_matched']]],
        ]);
    }

    public function test_runs_of_another_users_report_are_not_listable(): void
    {
        $other = Report::factory()->create();

        $this->assertFailureEnvelope($this->getJson("/api/v1/reports/{$other->id}/runs"), 403);
    }

    public function test_the_run_list_only_contains_runs_of_the_requested_report(): void
    {
        $this->report->runs()->create($this->runAttributes('2024-12-18'));
        Report::factory()->create(['user_id' => $this->user->id])
            ->runs()->create($this->runAttributes('2024-12-18'));

        $this->getJson("/api/v1/reports/{$this->report->id}/runs")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_a_finished_run_can_be_downloaded(): void
    {
        $this->postJson("/api/v1/reports/{$this->report->id}/run", [
            'from' => '2024-12-18', 'to' => '2024-12-19',
        ])->assertOk();

        $run = $this->report->runs()->firstOrFail();

        $response = $this->get("/api/v1/reports/{$this->report->id}/runs/{$run->id}/download");

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));
    }

    public function test_downloading_a_run_with_no_file_is_a_404(): void
    {
        $run = $this->report->runs()->create($this->runAttributes('2024-12-18'));

        $this->assertFailureEnvelope(
            $this->getJson("/api/v1/reports/{$this->report->id}/runs/{$run->id}/download"),
            404,
        );
    }

    /**
     * A run id from another report must not be reachable by pairing it with a
     * report the caller does own.
     */
    public function test_a_run_belonging_to_a_different_report_is_a_404(): void
    {
        $otherReport = Report::factory()->create(['user_id' => $this->user->id]);
        $foreignRun = $otherReport->runs()->create($this->runAttributes('2024-12-18'));

        $this->assertFailureEnvelope(
            $this->getJson("/api/v1/reports/{$this->report->id}/runs/{$foreignRun->id}/download"),
            404,
        );
    }

    public function test_downloading_another_users_run_is_forbidden(): void
    {
        $other = Report::factory()->create();
        $run = $other->runs()->create($this->runAttributes('2024-12-18'));

        $this->assertFailureEnvelope(
            $this->getJson("/api/v1/reports/{$other->id}/runs/{$run->id}/download"),
            403,
        );
    }

    public function test_a_run_without_an_explicit_window_uses_the_reports_period(): void
    {
        $this->postJson("/api/v1/reports/{$this->report->id}/run")->assertOk();

        $run = $this->report->runs()->firstOrFail();
        $expected = $this->report->period->windowStart(now());

        $this->assertSame(
            $expected->toDateTimeString(),
            $run->period_start->toDateTimeString(),
            'An on-demand run must use exactly the window the scheduler would have used.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runAttributes(string $day): array
    {
        return [
            'period_start' => "{$day} 00:00:00",
            'period_end' => "{$day} 23:59:59",
            'status' => 'succeeded',
            'rows' => 1,
            'total_matched' => 0,
        ];
    }
}
