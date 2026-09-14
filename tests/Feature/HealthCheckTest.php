<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_health_endpoint_is_public_and_reports_each_dependency(): void
    {
        $this->fakeSearch();

        $response = $this->getJson('/api/v1/up');

        $this->assertSuccessEnvelope($response);
        $response->assertJsonPath('data.status', 'ok');
        $response->assertJsonStructure(['data' => ['status', 'version', 'checks' => ['database', 'cache', 'search']]]);
    }

    /**
     * Search backs reporting, not the core API — an Elasticsearch outage must be
     * reported, not fatal.
     */
    public function test_a_search_outage_is_reported_without_failing_the_probe(): void
    {
        $this->fakeSearch()->setAvailable(false);

        $response = $this->getJson('/api/v1/up');

        $response->assertOk();
        $response->assertJsonPath('data.status', 'ok');
        $response->assertJsonPath('data.checks.search', false);
        $response->assertJsonPath('data.checks.database', true);
    }
}
