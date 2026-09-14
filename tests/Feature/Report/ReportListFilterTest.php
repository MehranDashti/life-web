<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use App\Models\User\User;
use App\Models\Report\Report;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The list endpoint's query surface: Osmose filters, sorting and pagination.
 *
 * The controlling rule throughout is that a client parameter narrows within the
 * caller's own rows and can never widen the set.
 */
class ReportListFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    public function test_it_paginates(): void
    {
        Report::factory()->count(25)->create(['user_id' => $this->user->id]);

        $first = $this->getJson('/api/v1/reports?page_size=10&current=1');
        $second = $this->getJson('/api/v1/reports?page_size=10&current=3');

        $first->assertJsonPath('data.pagination.total', 25);
        $this->assertCount(10, $first->json('data.list'));
        $this->assertCount(5, $second->json('data.list'));
    }

    public function test_pages_do_not_overlap(): void
    {
        Report::factory()->count(6)->create(['user_id' => $this->user->id]);

        $first = collect((array) $this->getJson('/api/v1/reports?page_size=3&current=1')->json('data.list'))->pluck('id');
        $second = collect((array) $this->getJson('/api/v1/reports?page_size=3&current=2')->json('data.list'))->pluck('id');

        $this->assertCount(0, $first->intersect($second), 'Page 1 and page 2 must be disjoint.');
    }

    public function test_the_camel_case_aliases_are_honoured(): void
    {
        Report::factory()->count(5)->create(['user_id' => $this->user->id]);

        $this->getJson('/api/v1/reports?pageSize=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.pagination.page_size', 2)
            ->assertJsonPath('data.pagination.current', 2);
    }

    public function test_an_out_of_range_page_returns_an_empty_list_not_an_error(): void
    {
        Report::factory()->create(['user_id' => $this->user->id]);

        $this->getJson('/api/v1/reports?page_size=10&current=99')
            ->assertOk()
            ->assertJsonPath('data.list', []);
    }

    public function test_a_zero_or_negative_page_falls_back_to_the_first(): void
    {
        Report::factory()->create(['user_id' => $this->user->id]);

        $this->getJson('/api/v1/reports?current=0')->assertJsonPath('data.pagination.current', 1);
        $this->getJson('/api/v1/reports?current=-5')->assertJsonPath('data.pagination.current', 1);
    }

    public function test_it_filters_by_partial_name(): void
    {
        Report::factory()->create(['user_id' => $this->user->id, 'name' => 'آلودگی تهران']);
        Report::factory()->create(['user_id' => $this->user->id, 'name' => 'ترافیک شیراز']);

        $this->getJson('/api/v1/reports?name='.urlencode('تهران'))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.list.0.name', 'آلودگی تهران');
    }

    public function test_it_filters_by_status(): void
    {
        Report::factory()->create(['user_id' => $this->user->id]);
        Report::factory()->paused()->create(['user_id' => $this->user->id]);

        $this->getJson('/api/v1/reports?status=paused')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.list.0.status', 'paused');
    }

    public function test_it_filters_by_keyword_inside_the_json_column(): void
    {
        Report::factory()->withKeywords(['تهران', 'آلودگی'])->create(['user_id' => $this->user->id]);
        Report::factory()->withKeywords(['شیراز'])->create(['user_id' => $this->user->id]);

        $this->getJson('/api/v1/reports?keyword='.urlencode('آلودگی'))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_filters_combine_as_a_conjunction(): void
    {
        Report::factory()->create(['user_id' => $this->user->id, 'name' => 'الف']);
        Report::factory()->paused()->create(['user_id' => $this->user->id, 'name' => 'الف']);

        $this->getJson('/api/v1/reports?name='.urlencode('الف').'&status=paused')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    /**
     * The `sorter` parameter is a JSON object of {column: ascend|descend}.
     */
    public function test_it_sorts_by_an_explicit_sorter(): void
    {
        Report::factory()->create(['user_id' => $this->user->id, 'name' => 'ب']);
        Report::factory()->create(['user_id' => $this->user->id, 'name' => 'الف']);

        $ascending = $this->getJson('/api/v1/reports?sorter='.urlencode('{"name":"ascend"}'));
        $descending = $this->getJson('/api/v1/reports?sorter='.urlencode('{"name":"descend"}'));

        $this->assertSame(
            array_reverse(collect((array) $ascending->json('data.list'))->pluck('name')->all()),
            collect((array) $descending->json('data.list'))->pluck('name')->all(),
        );
    }

    public function test_a_malformed_sorter_is_ignored_rather_than_fatal(): void
    {
        Report::factory()->create(['user_id' => $this->user->id]);

        $this->getJson('/api/v1/reports?sorter=not-json')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    /**
     * Osmose's date ranges read config/osmose.php, which the provider only
     * publishes and never merges — without the published file this silently
     * returns everything instead of filtering.
     */
    public function test_the_osmose_date_range_filter_is_wired(): void
    {
        Report::factory()->create(['user_id' => $this->user->id]);

        $this->assertIsArray(config('osmose.ranges'), 'config/osmose.php must be published.');

        $this->getJson('/api/v1/reports?range=y')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_an_explicit_created_at_window_filters(): void
    {
        $report = Report::factory()->create(['user_id' => $this->user->id]);
        $report->forceFill(['created_at' => Carbon::parse('2020-01-01')])->saveQuietly();

        $this->getJson('/api/v1/reports?from=2024-01-01&to=2024-12-31')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
    }

    public function test_the_newest_report_comes_first_by_default(): void
    {
        $older = Report::factory()->create(['user_id' => $this->user->id, 'name' => 'قدیمی']);
        $older->forceFill(['created_at' => Carbon::now()->subDay()])->saveQuietly();
        Report::factory()->create(['user_id' => $this->user->id, 'name' => 'تازه']);

        $this->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonPath('data.list.0.name', 'تازه');
    }

    public function test_soft_deleted_reports_are_excluded(): void
    {
        $report = Report::factory()->create(['user_id' => $this->user->id]);
        Report::factory()->create(['user_id' => $this->user->id]);
        $report->delete();

        $this->getJson('/api/v1/reports')->assertJsonPath('data.pagination.total', 1);
    }
}
