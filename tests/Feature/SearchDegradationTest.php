<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User\User;
use App\Models\Report\Report;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ThrowingSearchAdapter;
use App\Exceptions\SearchUnavailableException;
use App\Adapters\Contracts\SearchAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Elastic\Elasticsearch\Exception\ClientResponseException;

/**
 * Search backs reporting, not the core API, so an outage must degrade rather than
 * take the service down. These cover the seam where that is decided.
 */
class SearchDegradationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('reports');
    }

    public function test_the_health_probe_still_answers_when_search_is_down(): void
    {
        $this->fakeSearch()->setAvailable(false);

        $response = $this->getJson('/api/v1/up');

        $response->assertOk();
        $response->assertJsonPath('data.status', 'ok');
        $response->assertJsonPath('data.checks.search', false);
    }

    public function test_reports_can_still_be_listed_and_created_during_an_outage(): void
    {
        $this->app->instance(SearchAdapterInterface::class, new ThrowingSearchAdapter);
        Passport::actingAs(User::factory()->create());

        $this->postJson('/api/v1/reports', [
            'name' => 'گزارش',
            'period' => 'daily',
            'keywords' => ['تهران'],
        ])->assertOk();

        $this->getJson('/api/v1/reports')->assertOk();
    }

    /**
     * Elasticsearch's own exceptions are matched by INTERFACE, which the exact
     * class-name map in config/exceptions.php cannot express — so they are
     * normalised in bootstrap/app.php before they reach it.
     */
    public function test_an_elasticsearch_exception_is_rendered_as_a_503_envelope(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        $report = Report::factory()->create(['user_id' => $user->id]);

        // The real adapter translates engine exceptions at its own boundary, so
        // everything downstream sees the application's type — see
        // ElasticsearchAdapterTest for the translation itself.
        $this->app->instance(SearchAdapterInterface::class, new ThrowingSearchAdapter);

        $response = $this->postJson("/api/v1/reports/{$report->id}/run", [
            'from' => '2024-12-18',
            'to' => '2024-12-19',
        ]);

        $this->assertFailureEnvelope($response, 503);
        $this->assertStringNotContainsString('messages.', (string) $response->json('message'));
        $this->assertStringNotContainsString(
            'Elastic',
            (string) $response->json('message'),
            'Driver detail must not leak to the caller.',
        );
    }

    public function test_the_search_unavailable_exception_carries_a_503(): void
    {
        $exception = new SearchUnavailableException;

        $this->assertSame(503, $exception->getCode());
        $this->assertStringNotContainsString('messages.', $exception->getMessage());
    }

    public function test_the_original_failure_is_preserved_for_the_logs(): void
    {
        $previous = new ClientResponseException('connection refused');

        $this->assertSame($previous, (new SearchUnavailableException($previous))->getPrevious());
    }

    /**
     * The run is recorded as failed so the outage is inspectable after the fact,
     * rather than vanishing into a log line.
     */
    public function test_an_outage_during_generation_is_recorded_on_the_run(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        $report = Report::factory()->create(['user_id' => $user->id]);

        $this->app->instance(SearchAdapterInterface::class, new ThrowingSearchAdapter);

        $this->postJson("/api/v1/reports/{$report->id}/run", [
            'from' => '2024-12-18',
            'to' => '2024-12-19',
        ]);

        $run = $report->runs()->firstOrFail();

        $this->assertSame('failed', $run->status->value);
        $this->assertNotNull($run->error);
        Mail::assertNothingSent();
    }
}
