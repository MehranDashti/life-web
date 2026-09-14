<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;
use App\Models\Report\Report;
use App\Adapters\Fake\FakeSearchAdapter;
use Tests\Support\ThrowingSearchAdapter;
use App\Adapters\Contracts\SearchAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The operational commands. Run against the in-memory adapter so they need no
 * cluster; the real engine's behaviour is covered by tests/Integration.
 */
class PostIndexCommandTest extends TestCase
{
    use RefreshDatabase;

    private FakeSearchAdapter $search;

    /** @var array<int, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->search = $this->fakeSearch();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_setup_registers_the_template_and_reports_the_configuration(): void
    {
        $this->artisan('posts:setup')
            ->expectsOutputToContain('posts-template')
            ->assertSuccessful();
    }

    public function test_setup_fails_loudly_when_the_cluster_is_unreachable(): void
    {
        $this->unreachableCluster();

        $this->artisan('posts:setup')->assertFailed();
    }

    public function test_index_imports_the_supplied_corpus(): void
    {
        $this->artisan('posts:index')->assertSuccessful();

        $this->assertSame(21, $this->search->count());
    }

    public function test_index_is_idempotent(): void
    {
        $this->artisan('posts:index')->assertSuccessful();
        $this->artisan('posts:index')->assertSuccessful();

        $this->assertSame(21, $this->search->count());
    }

    public function test_index_accepts_an_explicit_file(): void
    {
        $path = $this->writeCorpus('[{"id":"a","title":"t","published_at":"2024-12-18T00:00:00Z"}]');

        $this->artisan("posts:index {$path}")->assertSuccessful();

        $this->assertSame(1, $this->search->count());
    }

    public function test_index_fails_on_a_missing_file(): void
    {
        $this->artisan('posts:index /no/such/corpus.json')->assertFailed();
    }

    public function test_index_fails_on_a_truncated_file_rather_than_importing_half_of_it(): void
    {
        $path = $this->writeCorpus('[{"id":"a","published_at":"2024-12-18T00:00:00Z"},{"id":"b"');

        $this->artisan("posts:index {$path}")->assertFailed();
    }

    public function test_index_fails_when_the_cluster_is_unreachable(): void
    {
        $this->unreachableCluster();

        $this->artisan('posts:index')->assertFailed();
    }

    public function test_index_honours_an_explicit_chunk_size(): void
    {
        $this->artisan('posts:index --chunk=5')->assertSuccessful();

        $this->assertSame(21, $this->search->count());
    }

    public function test_synthetic_generates_the_requested_volume(): void
    {
        $this->artisan('posts:synthetic 100')->assertSuccessful();

        $this->assertSame(100, $this->search->count());
    }

    public function test_synthetic_reports_the_documented_selectivity(): void
    {
        $this->artisan('posts:synthetic 50')
            ->expectsOutputToContain('تهران')
            ->assertSuccessful();
    }

    public function test_synthetic_rejects_a_non_positive_count(): void
    {
        $this->artisan('posts:synthetic 0')->assertFailed();
    }

    public function test_synthetic_rejects_an_inverted_date_range(): void
    {
        $this->artisan('posts:synthetic 10 --from=2024-12-31 --to=2024-01-01')->assertFailed();
    }

    public function test_synthetic_is_reproducible_from_its_seed(): void
    {
        $this->artisan('posts:synthetic 20 --seed=99')->assertSuccessful();
        $first = $this->search->count();

        $this->artisan('posts:flush --force')->assertSuccessful();
        $this->artisan('posts:synthetic 20 --seed=99')->assertSuccessful();

        $this->assertSame($first, $this->search->count());
    }

    public function test_flush_drops_the_corpus(): void
    {
        $this->artisan('posts:index')->assertSuccessful();
        $this->assertSame(21, $this->search->count());

        $this->artisan('posts:flush --force')->assertSuccessful();

        $this->assertSame(0, $this->search->count());
    }

    public function test_the_benchmark_refuses_to_run_without_a_cluster(): void
    {
        $this->unreachableCluster();

        $this->artisan('bench:search --sizes=10')->assertFailed();
        $this->artisan('bench:report --sizes=10')->assertFailed();
    }

    /**
     * bench:report measures a real subscription, so it cannot invent one.
     */
    public function test_the_report_benchmark_refuses_to_run_with_no_report(): void
    {
        $this->assertSame(0, Report::query()->count());

        $this->artisan('bench:report --sizes=10')->assertFailed();
    }

    private function unreachableCluster(): void
    {
        $this->app->instance(SearchAdapterInterface::class, new ThrowingSearchAdapter);
    }

    private function writeCorpus(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lifeweb-corpus-').'.json';
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
