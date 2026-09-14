<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Tests\TestCase;
use App\Models\User\User;
use Laravel\Passport\Passport;
use Database\Seeders\PostSeeder;
use Database\Seeders\UserSeeder;
use Tests\Support\ThrowingSearchAdapter;
use Database\Seeders\PassportClientSeeder;
use App\Adapters\Contracts\SearchAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Seeders run from the container entrypoint on every boot, so "idempotent" is a
 * hard requirement rather than a nicety.
 */
class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_passport_client_seeder_creates_the_personal_access_client(): void
    {
        // The base TestCase already ran it; re-running must not add a second.
        $before = Passport::client()->newQuery()->count();

        (new PassportClientSeeder)->run();

        $this->assertSame($before, Passport::client()->newQuery()->count());
        $this->assertGreaterThan(0, $before, 'Sign-in is impossible without this client.');
    }

    public function test_the_passport_client_seeder_is_safe_to_run_repeatedly(): void
    {
        (new PassportClientSeeder)->run();
        (new PassportClientSeeder)->run();

        $personalAccessClients = Passport::client()->newQuery()->get()
            ->filter(fn ($client): bool => $client->hasGrantType('personal_access'));

        $this->assertCount(1, $personalAccessClients);
    }

    public function test_the_user_seeder_creates_the_demo_account(): void
    {
        (new UserSeeder)->run();

        $this->assertDatabaseHas('users', ['username' => 'demo', 'email' => 'demo@lifeweb.local']);
    }

    public function test_the_user_seeder_is_idempotent(): void
    {
        (new UserSeeder)->run();
        (new UserSeeder)->run();

        $this->assertSame(1, User::query()->where('username', 'demo')->count());
    }

    public function test_the_demo_account_can_actually_sign_in(): void
    {
        (new UserSeeder)->run();

        $this->postJson('/api/v1/auth/login', ['username' => 'demo', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_the_post_seeder_imports_the_supplied_corpus(): void
    {
        $search = $this->fakeSearch();

        (new PostSeeder)->run();

        $this->assertSame(21, $search->count(), 'data.json holds 21 posts.');
    }

    public function test_the_post_seeder_is_idempotent(): void
    {
        $search = $this->fakeSearch();

        (new PostSeeder)->run();
        (new PostSeeder)->run();

        $this->assertSame(21, $search->count());
    }

    /**
     * Elasticsearch is a soft dependency: it backs reporting, not the core API. A
     * seeder that hard-failed would make `make fresh` impossible without the full
     * stack running.
     */
    public function test_the_post_seeder_skips_when_the_cluster_is_unreachable(): void
    {
        $this->app->instance(SearchAdapterInterface::class, new ThrowingSearchAdapter);

        (new PostSeeder)->run();

        // Completing without an exception is the point; the index is simply left
        // empty for `posts:index` to fill once the cluster is back.
        $this->assertSame(0, app(SearchAdapterInterface::class)->count());
    }

    public function test_the_corpus_file_the_seeder_reads_is_present(): void
    {
        $this->assertFileExists(base_path(PostSeeder::CORPUS));
    }
}
