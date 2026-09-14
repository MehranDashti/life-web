<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User\User;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Laravel 13 ships no default `api` limiter, but bootstrap/app.php applies
 * throttleApi('api') to the whole surface — so if AppServiceProvider stops
 * defining it, every authenticated request 500s. That is the main thing these
 * tests protect.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('api');
    }

    public function test_the_api_limiter_is_defined(): void
    {
        $this->assertNotNull(
            RateLimiter::limiter('api'),
            'bootstrap/app.php applies throttleApi("api"); without the limiter every request 500s.',
        );
    }

    public function test_an_authenticated_request_is_not_rate_limited_at_normal_volume(): void
    {
        Passport::actingAs(User::factory()->create());

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/reports')->assertOk();
        }
    }

    public function test_the_limit_is_configurable(): void
    {
        config(['app.api_rate_limit' => 3]);
        Passport::actingAs(User::factory()->create());

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/reports')->assertOk();
        }

        $response = $this->getJson('/api/v1/reports');

        // A Symfony HttpException carries its status in getStatusCode(), not
        // getCode(), which is all the exception map reads — so without explicit
        // handling this is a 500 and the client has no Retry-After to back off on.
        $this->assertFailureEnvelope($response, 429);
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertStringNotContainsString('messages.', (string) $response->json('message'));
    }

    /**
     * Authenticated callers are bucketed per user id, so one noisy client cannot
     * consume another's budget.
     */
    public function test_one_users_budget_does_not_consume_anothers(): void
    {
        config(['app.api_rate_limit' => 2]);

        Passport::actingAs(User::factory()->create());
        $this->getJson('/api/v1/reports')->assertOk();
        $this->getJson('/api/v1/reports')->assertOk();
        $this->getJson('/api/v1/reports')->assertStatus(429);

        $this->app->make('auth')->forgetGuards();
        Passport::actingAs(User::factory()->create());

        $this->getJson('/api/v1/reports')->assertOk();
    }

    /**
     * Login is unauthenticated and password-checking is expensive, so it carries a
     * tighter limit of its own on top of the global one.
     */
    public function test_login_is_throttled_independently(): void
    {
        User::factory()->create(['username' => 'demo', 'password' => Hash::make('password')]);

        $lastStatus = 200;

        for ($i = 0; $i < 12; $i++) {
            $lastStatus = $this->postJson('/api/v1/auth/login', [
                'username' => 'demo',
                'password' => 'wrong-password',
            ])->getStatusCode();
        }

        $this->assertSame(429, $lastStatus, 'Repeated sign-in attempts must eventually be throttled.');
    }

    public function test_the_health_endpoint_stays_reachable(): void
    {
        $this->fakeSearch();

        $this->getJson('/api/v1/up')->assertOk();
    }
}
