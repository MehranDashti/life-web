<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use App\Models\Report\Report;
use App\Models\Report\ReportRun;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Mail;
use App\Adapters\Cached\CorpusVersion;
use Illuminate\Support\Facades\Storage;
use App\Adapters\Fake\FakeSearchAdapter;
use Tests\Support\CountingSearchAdapter;
use App\Adapters\Cached\CachedSearchAdapter;
use App\Adapters\Contracts\SearchAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The reason the cache exists.
 *
 * Search is only reached during report generation, so load concentrates entirely
 * on the scheduler tick — and many due reports issue IDENTICAL aggregations,
 * because different users track overlapping keywords over the same window. These
 * tests assert the collapse in the only terms that matter: how many aggregations
 * actually reach Elasticsearch.
 */
class ReportDispatchCacheTest extends TestCase
{
    use RefreshDatabase;

    private CountingSearchAdapter $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('reports');

        $fake = new FakeSearchAdapter;
        $fake->bulkIndex([
            $this->makePost('1', 'آلودگی هوای تهران', '2024-12-18T08:00:00Z'),
            $this->makePost('2', 'ترافیک اصفهان', '2024-12-18T09:00:00Z'),
        ]);

        $this->engine = new CountingSearchAdapter($fake);

        $cache = new Repository(new ArrayStore);

        $this->app->instance(SearchAdapterInterface::class, new CachedSearchAdapter(
            inner: $this->engine,
            cache: $cache,
            version: new CorpusVersion($cache),
            ttl: 900,
            countTtl: 60,
        ));
    }

    /**
     * Five subscribers tracking the same thing over the same window is one
     * question, and should cost one answer.
     */
    public function test_reports_sharing_keywords_and_window_cost_one_aggregation(): void
    {
        Report::factory()->due()->withKeywords(['تهران'])->count(5)->create();

        $this->artisan('reports:dispatch daily --sync')->assertSuccessful();

        $this->assertSame(5, Report::query()->count());
        $this->assertSame(1, $this->engine->histogramCalls, 'Five identical queries must collapse to one.');
    }

    public function test_every_report_still_receives_its_own_run_and_counts(): void
    {
        Report::factory()->due()->withKeywords(['تهران'])->count(3)->create();

        $this->artisan('reports:dispatch daily --sync')->assertSuccessful();

        $runs = ReportRun::query()->get();

        $this->assertCount(3, $runs, 'Collapsing the query must not collapse the reports.');
        $this->assertSame(1, $runs->pluck('total_matched')->unique()->count());
        Mail::assertSentCount(3);
    }

    public function test_reports_with_different_keywords_do_not_collapse(): void
    {
        Report::factory()->due()->withKeywords(['تهران'])->create();
        Report::factory()->due()->withKeywords(['اصفهان'])->create();

        $this->artisan('reports:dispatch daily --sync')->assertSuccessful();

        $this->assertSame(2, $this->engine->histogramCalls);
    }

    /**
     * The saving scales with the number of subscribers, which is the point: the
     * engine sees distinct questions, not subscribers.
     */
    public function test_the_engine_sees_distinct_questions_not_subscribers(): void
    {
        Report::factory()->due()->withKeywords(['تهران'])->count(10)->create();
        Report::factory()->due()->withKeywords(['اصفهان'])->count(10)->create();

        $this->artisan('reports:dispatch daily --sync')->assertSuccessful();

        $this->assertSame(20, Report::query()->count());
        $this->assertSame(2, $this->engine->histogramCalls);
    }

    /**
     * A cached run spent no engine time, and saying so is what makes the collapse
     * visible in the data rather than only in a test.
     */
    public function test_a_collapsed_run_records_zero_query_time(): void
    {
        Report::factory()->due()->withKeywords(['تهران'])->count(3)->create();

        $this->artisan('reports:dispatch daily --sync')->assertSuccessful();

        $queryTimes = ReportRun::query()->pluck('query_took_ms');

        $this->assertCount(3, $queryTimes);
        $this->assertGreaterThanOrEqual(2, $queryTimes->filter(fn ($ms): bool => $ms === 0)->count());
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
