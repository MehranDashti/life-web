<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use App\Models\User\User;
use App\Models\Report\Report;
use Laravel\Passport\Passport;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The query cache is OFF for the rest of the suite, so these are the only tests
 * that exercise the cached read path — and it has its own failure modes.
 *
 * The one that shipped and had to be fixed: `renderFilter()` returned a
 * JsonResource collection, which is not safely serialisable. Cached to Redis and
 * read back it became `__PHP_Incomplete_Class`, so the endpoint returned garbage
 * as soon as the cache was warm. It never showed up locally because the default
 * `file` store cannot tag, so caching was skipped entirely.
 */
class ReportListCacheTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // The array store IS taggable, so this turns the cached path on.
        config(['cache.query_cache_enabled' => true, 'cache.default' => 'array']);

        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    public function test_a_cached_list_survives_a_round_trip_through_the_cache(): void
    {
        Report::factory()->create(['user_id' => $this->user->id, 'name' => 'گزارش اول']);

        $first = $this->getJson('/api/v1/reports');
        $second = $this->getJson('/api/v1/reports');

        $this->assertSuccessEnvelope($first);
        $this->assertSuccessEnvelope($second);

        // The payload must be identical AND well formed — not a serialised object.
        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertIsList($second->json('data.list'));
        $this->assertSame('گزارش اول', $second->json('data.list.0.name'));
    }

    public function test_a_cached_list_never_contains_a_serialised_resource_object(): void
    {
        Report::factory()->create(['user_id' => $this->user->id]);

        $this->getJson('/api/v1/reports')->assertOk();
        $second = $this->getJson('/api/v1/reports');

        $this->assertStringNotContainsString('__PHP_Incomplete_Class', (string) $second->getContent());
        $this->assertStringNotContainsString('AnonymousResourceCollection', (string) $second->getContent());
        $second->assertJsonStructure(['data' => ['list' => [['id', 'name', 'period', 'status']]]]);
    }

    /**
     * The cache key includes the authenticated user; without that, a warm cache
     * would serve one user's reports to another.
     */
    public function test_one_users_cached_list_is_never_served_to_another(): void
    {
        Report::factory()->create(['user_id' => $this->user->id, 'name' => 'mine']);

        $this->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonPath('data.list.0.name', 'mine');

        $other = User::factory()->create();
        Report::factory()->create(['user_id' => $other->id, 'name' => 'theirs']);

        $this->app->make('auth')->forgetGuards();
        Passport::actingAs($other);

        $this->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.list.0.name', 'theirs');
    }

    public function test_creating_a_report_invalidates_the_owners_cached_list(): void
    {
        Report::factory()->create(['user_id' => $this->user->id]);

        $this->getJson('/api/v1/reports')->assertJsonPath('data.pagination.total', 1);

        $this->postJson('/api/v1/reports', [
            'name' => 'گزارش تازه',
            'period' => 'daily',
            'keywords' => ['تهران'],
        ])->assertOk();

        $this->getJson('/api/v1/reports')->assertJsonPath('data.pagination.total', 2);
    }

    /**
     * A store that cannot tag must skip caching rather than throwing — a caching
     * optimisation is never a good reason for a request to fail.
     */
    public function test_a_non_taggable_store_degrades_instead_of_failing(): void
    {
        config(['cache.default' => 'file']);
        Report::factory()->create(['user_id' => $this->user->id]);

        $this->assertSuccessEnvelope($this->getJson('/api/v1/reports'));
    }
}
