<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use App\Models\Report\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Kept separate from ReportManagementTest because that class authenticates in
 * setUp(); these assertions are only meaningful with no user at all.
 */
class ReportAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_report_route_rejects_an_anonymous_caller(): void
    {
        $report = Report::factory()->create();
        $run = $report->runs()->create([
            'period_start' => now()->subDay()->startOfDay(),
            'period_end' => now()->subDay()->endOfDay(),
            'status' => 'succeeded',
        ]);

        $routes = [
            ['getJson', '/api/v1/reports'],
            ['postJson', '/api/v1/reports'],
            ['getJson', "/api/v1/reports/{$report->id}"],
            ['getJson', "/api/v1/reports/{$report->id}/runs"],
            ['postJson', "/api/v1/reports/{$report->id}/run"],
            ['getJson', "/api/v1/reports/{$report->id}/runs/{$run->id}/download"],
        ];

        foreach ($routes as [$method, $uri]) {
            $this->assertFailureEnvelope($this->{$method}($uri), 401);
        }
    }
}
