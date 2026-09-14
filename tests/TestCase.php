<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Seeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use App\Adapters\Fake\FakeSearchAdapter;
use Database\Seeders\PassportClientSeeder;
use App\Adapters\Contracts\SearchAdapterInterface;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase wipes the OAuth client that `$user->createToken()` needs, so
     * every test that touches the database re-seeds it. Only this seeder runs — the
     * demo user is not created, so tests control their own fixtures.
     */
    protected bool $seed = true;

    /** @var class-string<Seeder> */
    protected string $seeder = PassportClientSeeder::class;

    /**
     * The in-memory search double bound for this test, so a test can seed
     * documents and assert on histogram output without any infrastructure.
     */
    protected function fakeSearch(): FakeSearchAdapter
    {
        $adapter = new FakeSearchAdapter;

        $this->app->instance(SearchAdapterInterface::class, $adapter);

        return $adapter;
    }

    /**
     * Drop the guard's cached user between requests in the same test method.
     *
     * Laravel's TokenGuard memoises the resolved user, and a test method reuses one
     * application instance across requests — so without this, a request made after a
     * token was revoked would still see the cached user. Production is unaffected:
     * Octane flushes authentication state on every RequestReceived event.
     */
    protected function forgetAuthState(): void
    {
        $this->app->make('auth')->forgetGuards();
    }

    /**
     * Assert the standard success envelope, so a change to the response shape
     * fails loudly in one place rather than silently across every consumer.
     *
     * @param  TestResponse<JsonResponse>  $response
     */
    protected function assertSuccessEnvelope($response, int $code = 200): void
    {
        $response->assertStatus($code);
        $response->assertJsonStructure(['success', 'code', 'message', 'data']);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('code', $code);
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     */
    protected function assertFailureEnvelope($response, int $code): void
    {
        $response->assertStatus($code);
        $response->assertJsonStructure(['success', 'code', 'message', 'error']);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('code', $code);
    }
}
