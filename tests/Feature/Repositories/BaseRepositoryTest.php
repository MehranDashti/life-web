<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use Tests\TestCase;
use App\Models\User\User;
use App\Enums\ReportStatus;
use App\Models\Report\Report;
use Laravel\Passport\Passport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Repositories\Contracts\Report\ReportRepositoryInterface;

/**
 * The generic CRUD surface every repository inherits. It is exercised indirectly
 * by the HTTP tests, but its own behaviours — blamable stamping, condition
 * matching, quiet saves — deserve direct coverage because every domain depends on
 * them being right.
 */
class BaseRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ReportRepositoryInterface $repository;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ReportRepositoryInterface::class);
        $this->user = User::factory()->create();
    }

    public function test_it_exposes_its_model(): void
    {
        $this->assertInstanceOf(Report::class, $this->repository->getModel());
    }

    public function test_create_persists_and_refreshes_the_model(): void
    {
        $report = $this->createReport(['name' => 'گزارش']);

        $this->assertTrue($report->exists);
        $this->assertTrue($report->created_at->isToday());
        // Refreshed from the database, so column defaults are present.
        $this->assertSame(ReportStatus::Active, $report->status);
        $this->assertDatabaseHas('reports', ['name' => 'گزارش']);
    }

    /**
     * created_by/updated_by carry the acting user, which is what makes the audit
     * trail usable.
     */
    public function test_create_stamps_the_acting_user(): void
    {
        Passport::actingAs($this->user);

        $report = $this->createReport();

        $this->assertSame($this->user->id, $report->created_by);
        $this->assertSame($this->user->id, $report->updated_by);
    }

    /**
     * Seeders and scheduled jobs have no acting user; null is a legitimate value,
     * not an error.
     */
    public function test_a_system_write_leaves_the_blamable_columns_null(): void
    {
        $report = $this->createReport();

        $this->assertNull($report->created_by);
        $this->assertNull($report->updated_by);
    }

    public function test_stamping_can_be_switched_off(): void
    {
        Passport::actingAs($this->user);

        $report = $this->createReport(setCreateFlag: false, setUpdateFlag: false);

        $this->assertNull($report->created_by);
        $this->assertNull($report->updated_by);
    }

    public function test_update_persists_changes_and_restamps(): void
    {
        $report = $this->createReport();
        Passport::actingAs($this->user);

        $this->repository->update($report, ['name' => 'تازه']);

        $this->assertSame('تازه', $report->fresh()->name);
        $this->assertSame($this->user->id, $report->fresh()->updated_by);
    }

    public function test_delete_soft_deletes_and_records_who(): void
    {
        $report = $this->createReport();
        Passport::actingAs($this->user);

        $this->repository->delete($report);

        $this->assertSoftDeleted('reports', ['id' => $report->id]);
        $this->assertSame($this->user->id, Report::withTrashed()->findOrFail($report->id)->deleted_by);
    }

    public function test_find_by_attribute_returns_a_match_or_null(): void
    {
        $report = $this->createReport(['name' => 'یکتا']);

        $this->assertSame($report->id, $this->repository->findByAttribute('name', 'یکتا')?->getKey());
        $this->assertNull($this->repository->findByAttribute('name', 'ندارد'));
    }

    public function test_find_in_by_attribute_returns_every_match(): void
    {
        $a = $this->createReport(['name' => 'a']);
        $b = $this->createReport(['name' => 'b']);
        $this->createReport(['name' => 'c']);

        $found = $this->repository->findInByAttribute('name', ['a', 'b']);

        $this->assertCount(2, $found);
        $this->assertEqualsCanonicalizing(
            [$a->getKey(), $b->getKey()],
            $found->pluck('id')->all(),
        );
    }

    public function test_find_or_create_returns_the_existing_record(): void
    {
        $existing = $this->createReport(['name' => 'یکتا']);

        $found = $this->repository->findOrCreate(['name' => 'یکتا'], $this->attributes());

        $this->assertSame($existing->getKey(), $found->getKey());
        $this->assertSame(1, Report::query()->count());
    }

    public function test_find_or_create_creates_when_absent(): void
    {
        $created = $this->repository->findOrCreate(['name' => 'تازه'], $this->attributes());

        $this->assertInstanceOf(Report::class, $created);
        $this->assertSame('تازه', $created->name);
        $this->assertSame(1, Report::query()->count());
    }

    public function test_update_or_create_updates_in_place(): void
    {
        $this->createReport(['name' => 'یکتا', 'match_all_keywords' => false]);

        $this->repository->updateOrCreate(['name' => 'یکتا'], ['match_all_keywords' => true]);

        $this->assertSame(1, Report::query()->count());
        $this->assertTrue((bool) Report::query()->firstOrFail()->match_all_keywords);
    }

    public function test_find_by_conditions_matches_scalars_arrays_and_nulls(): void
    {
        $this->createReport(['name' => 'a', 'period' => 'daily']);
        $this->createReport(['name' => 'b', 'period' => 'weekly']);

        $this->assertCount(1, $this->repository->findByConditions(['name' => 'a']));
        $this->assertCount(2, $this->repository->findByConditions(['period' => ['daily', 'weekly']]));
        // Nothing has run yet, so last_run_at is null on every row.
        $this->assertCount(2, $this->repository->findByConditions(['last_run_at' => null]));
    }

    public function test_first_by_conditions_returns_one_or_null(): void
    {
        $this->createReport(['name' => 'a']);

        $this->assertNotNull($this->repository->firstByConditions(['name' => 'a']));
        $this->assertNull($this->repository->firstByConditions(['name' => 'zzz']));
    }

    public function test_update_by_conditions_reports_the_affected_row_count(): void
    {
        $this->createReport(['period' => 'daily']);
        $this->createReport(['period' => 'daily']);
        $this->createReport(['period' => 'weekly']);

        $affected = $this->repository->updateByConditions(['period' => 'daily'], ['status' => 'paused']);

        $this->assertSame(2, $affected);
        $this->assertSame(1, Report::query()->where('status', 'active')->count());
    }

    public function test_delete_by_conditions_reports_the_affected_row_count(): void
    {
        $this->createReport(['period' => 'daily']);
        $this->createReport(['period' => 'weekly']);

        $this->assertSame(1, $this->repository->deleteByConditions(['period' => 'daily']));
        $this->assertSame(1, Report::query()->count());
    }

    public function test_find_all_returns_every_record(): void
    {
        $this->createReport();
        $this->createReport();

        $this->assertCount(2, $this->repository->findAll());
    }

    public function test_get_latest_record_returns_the_newest(): void
    {
        $this->createReport(['name' => 'old']);
        $newest = $this->createReport(['name' => 'new']);

        $this->assertSame($newest->getKey(), $this->repository->getLatestRecord()?->getKey());
    }

    public function test_update_attribute_writes_a_single_column(): void
    {
        $report = $this->createReport();

        $this->repository->updateAttribute($report, 'name', 'جدید');

        $this->assertSame('جدید', $report->fresh()->name);
    }

    public function test_change_status_writes_the_status_and_restamps(): void
    {
        Passport::actingAs($this->user);
        $report = $this->createReport();

        $this->repository->changeStatus($report, ReportStatus::Paused->value);

        $this->assertSame(ReportStatus::Paused, $report->fresh()->status);
        $this->assertSame($this->user->id, $report->fresh()->updated_by);
    }

    public function test_get_author_returns_the_authenticated_user_or_null(): void
    {
        $this->assertNull($this->repository->getAuthor());

        Passport::actingAs($this->user);

        $this->assertSame($this->user->id, $this->repository->getAuthor());
    }

    public function test_select_and_with_items_are_configurable(): void
    {
        $this->repository->setSelectItems(['id', 'name'])->setWithItems(['user']);

        $this->assertSame(['id', 'name'], $this->repository->getSelectItems());
        $this->assertSame(['user'], $this->repository->getWithItems());
    }

    /**
     * The repository contract returns Model, because it is generic. Narrowing once
     * here keeps every call site typed without repeating the assertion.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createReport(array $attributes = [], bool $setCreateFlag = true, bool $setUpdateFlag = true): Report
    {
        $report = $this->repository->create($this->attributes($attributes), $setCreateFlag, $setUpdateFlag);

        $this->assertInstanceOf(Report::class, $report);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributes(array $overrides = []): array
    {
        return array_merge([
            'user_id' => $this->user->id,
            'name' => 'گزارش '.fake()->unique()->word(),
            'period' => 'daily',
            'keywords' => ['تهران'],
        ], $overrides);
    }
}
