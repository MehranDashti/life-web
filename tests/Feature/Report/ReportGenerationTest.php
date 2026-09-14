<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use RuntimeException;
use App\Models\User\User;
use App\Mail\ReportReadyMail;
use App\Models\Report\Report;
use App\Enums\ReportRunStatus;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use App\Jobs\GenerateReportJob;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ThrowingSearchAdapter;
use App\Adapters\Contracts\Data\HistogramBucket;
use App\Services\Report\ReportGenerationService;
use App\Adapters\Contracts\SearchAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReportGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('reports');

        $this->user = User::factory()->create();
        Passport::actingAs($this->user);

        $search = $this->fakeSearch();
        $search->bulkIndex([
            $this->makePost('1', 'آلودگی هوای تهران', '2024-12-18T08:00:00Z'),
            $this->makePost('2', 'ترافیک تهران', '2024-12-18T20:00:00Z'),
            $this->makePost('3', 'آلودگی اصفهان', '2024-12-20T09:00:00Z'),
        ]);
    }

    public function test_an_on_demand_run_produces_a_histogram_and_a_file(): void
    {
        $report = $this->report(['تهران']);

        $response = $this->postJson("/api/v1/reports/{$report->id}/run", [
            'from' => '2024-12-18', 'to' => '2024-12-20',
        ]);

        $this->assertSuccessEnvelope($response);
        $response->assertJsonPath('data.status', 'succeeded');
        $response->assertJsonPath('data.total_matched', 2);
        $response->assertJsonPath('data.rows', 3);
        $response->assertJsonPath('data.has_file', true);

        Storage::disk('reports')->assertExists((string) $report->runs()->firstOrFail()->file_path);
    }

    public function test_the_run_records_query_and_export_time_separately(): void
    {
        $report = $this->report(['تهران']);

        $this->postJson("/api/v1/reports/{$report->id}/run", ['from' => '2024-12-18', 'to' => '2024-12-20'])
            ->assertOk();

        $run = $report->runs()->firstOrFail();

        $this->assertNotNull($run->duration_ms);
        $this->assertNotNull($run->query_took_ms);
        $this->assertNotNull($run->export_ms);
    }

    public function test_the_generated_report_is_emailed_to_its_owner(): void
    {
        $report = $this->report(['تهران']);

        $this->postJson("/api/v1/reports/{$report->id}/run", ['from' => '2024-12-18', 'to' => '2024-12-20'])
            ->assertOk();

        Mail::assertSent(ReportReadyMail::class, fn (ReportReadyMail $mail): bool => $mail->hasTo($this->user->email));
        $this->assertNotNull($report->runs()->firstOrFail()->delivered_at);
    }

    /**
     * The whole idempotency guarantee: a duplicate dispatch must not produce a
     * second run, a second file, or a second email.
     */
    public function test_running_the_same_window_twice_produces_one_run_and_one_email(): void
    {
        $report = $this->report(['تهران']);
        $payload = ['from' => '2024-12-18', 'to' => '2024-12-20'];

        $this->postJson("/api/v1/reports/{$report->id}/run", $payload)->assertOk();
        $this->postJson("/api/v1/reports/{$report->id}/run", $payload)->assertOk();

        $this->assertSame(1, $report->runs()->count());
        Mail::assertSentCount(1);
    }

    public function test_a_failed_window_can_be_retried(): void
    {
        $report = $this->report(['تهران']);
        $from = Carbon::parse('2024-12-18T00:00:00Z');
        $to = Carbon::parse('2024-12-20T23:59:59Z');

        $report->runs()->create([
            'period_start' => $from,
            'period_end' => $to,
            'status' => ReportRunStatus::Failed,
            'error' => 'previous failure',
            'attempts' => 1,
        ]);

        dispatch_sync(new GenerateReportJob((string) $report->id, $from->toIso8601String(), $to->toIso8601String()));

        $run = $report->runs()->firstOrFail();

        $this->assertSame(ReportRunStatus::Succeeded, $run->status);
        $this->assertSame(2, $run->attempts);
        $this->assertNull($run->error);
        $this->assertSame(1, $report->runs()->count());
    }

    public function test_a_search_outage_fails_the_run_and_does_not_advance_the_schedule(): void
    {
        $report = $this->report(['تهران']);
        $scheduledFor = $report->next_run_at;

        $this->app->instance(SearchAdapterInterface::class, new ThrowingSearchAdapter);

        $from = Carbon::parse('2024-12-18T00:00:00Z');
        $to = Carbon::parse('2024-12-20T23:59:59Z');

        try {
            dispatch_sync(new GenerateReportJob((string) $report->id, $from->toIso8601String(), $to->toIso8601String()));
            $this->fail('The job should have rethrown so the queue can retry it.');
        } catch (RuntimeException $exception) {
            $this->assertSame('cluster unavailable', $exception->getMessage());
        }

        $run = $report->runs()->firstOrFail();
        $report->refresh();

        $this->assertSame(ReportRunStatus::Failed, $run->status);
        $this->assertStringContainsString('cluster unavailable', (string) $run->error);
        $this->assertSame(1, $report->consecutive_failures);
        $this->assertTrue($scheduledFor?->equalTo($report->next_run_at), 'A failure must not skip the window.');
        Mail::assertNothingSent();
    }

    public function test_a_report_is_paused_after_repeated_failures(): void
    {
        $report = $this->report(['تهران']);
        $report->forceFill(['consecutive_failures' => GenerateReportJob::FAILURE_THRESHOLD - 1])->save();

        $this->app->instance(SearchAdapterInterface::class, new ThrowingSearchAdapter(new RuntimeException('boom')));

        $from = Carbon::parse('2024-12-18T00:00:00Z');

        try {
            dispatch_sync(new GenerateReportJob(
                (string) $report->id,
                $from->toIso8601String(),
                $from->copy()->addDay()->toIso8601String(),
            ));
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('paused', $report->refresh()->status->value);
    }

    public function test_the_histogram_includes_zero_count_days(): void
    {
        $report = $this->report(['تهران']);

        $result = app(ReportGenerationService::class)->compute(
            $report,
            Carbon::parse('2024-12-18T00:00:00Z'),
            Carbon::parse('2024-12-20T23:59:59Z'),
        );

        $counts = array_map(static fn (HistogramBucket $b): int => $b->count, $result->histogram->buckets);

        // Buckets are Tehran calendar days, and the UTC range given here spans four
        // of them (2024-12-20T23:59Z is already 2024-12-21 local). That is the
        // intended semantics: the corpus is Persian news, so local days are the
        // meaningful unit.
        $this->assertCount(4, $counts);
        $this->assertSame(
            ['2024-12-18', '2024-12-19', '2024-12-20', '2024-12-21'],
            array_map(
                static fn (HistogramBucket $b): string => $b->date->copy()->timezone('Asia/Tehran')->format('Y-m-d'),
                $result->histogram->buckets,
            ),
        );
        $this->assertContains(0, $counts, 'A day with no matching posts must still appear.');
        $this->assertSame(2, $result->histogram->total);
        $this->assertSame(2, array_sum($counts));
    }

    public function test_running_another_users_report_is_forbidden(): void
    {
        $report = Report::factory()->create();

        $this->assertFailureEnvelope(
            $this->postJson("/api/v1/reports/{$report->id}/run", ['from' => '2024-12-18', 'to' => '2024-12-20']),
            403,
        );
    }

    public function test_an_excessively_wide_window_is_rejected(): void
    {
        $report = $this->report(['تهران']);

        $this->assertFailureEnvelope(
            $this->postJson("/api/v1/reports/{$report->id}/run", ['from' => '2000-01-01', 'to' => '2024-12-20']),
            422,
        );
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function report(array $keywords): Report
    {
        return Report::factory()->withKeywords($keywords)->create(['user_id' => $this->user->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function makePost(string $id, string $title, string $publishedAt): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'lead' => '',
            'content' => '',
            'published_at' => $publishedAt,
        ];
    }
}
