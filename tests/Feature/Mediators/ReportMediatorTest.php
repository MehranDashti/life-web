<?php

declare(strict_types=1);

namespace Tests\Feature\Mediators;

use Tests\TestCase;
use RuntimeException;
use App\Models\User\User;
use App\Models\Report\Report;
use App\Models\Report\ReportRun;
use App\Mediators\Report\ReportMediator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * This project has no role or permission system — the task declares authorization
 * out of scope. Ownership is different: it is data integrity, and it is enforced
 * here so the same rule holds from HTTP and from a queued job alike.
 */
class ReportMediatorTest extends TestCase
{
    use RefreshDatabase;

    private ReportMediator $mediator;

    private User $owner;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediator = app(ReportMediator::class);
        $this->owner = User::factory()->create();
        $this->report = Report::factory()->create(['user_id' => $this->owner->id]);
    }

    public function test_the_owner_passes_the_ownership_guard(): void
    {
        $this->assertSame($this->mediator, $this->mediator->assertOwnedBy($this->report, $this->owner));
    }

    public function test_a_stranger_is_rejected_with_403(): void
    {
        try {
            $this->mediator->assertOwnedBy($this->report, User::factory()->create());
            $this->fail('A non-owner must not pass the ownership guard.');
        } catch (RuntimeException $exception) {
            $this->assertSame(403, $exception->getCode());
            $this->assertStringNotContainsString('messages.', $exception->getMessage());
        }
    }

    public function test_a_run_of_the_report_passes(): void
    {
        $run = $this->makeRun($this->report);

        $this->assertSame($this->mediator, $this->mediator->assertRunBelongsTo($run, $this->report));
    }

    /**
     * A run id from another report must not become reachable by pairing it with a
     * report the caller does own.
     */
    public function test_a_run_of_a_different_report_is_a_404_not_a_403(): void
    {
        $foreign = $this->makeRun(Report::factory()->create(['user_id' => $this->owner->id]));

        try {
            $this->mediator->assertRunBelongsTo($foreign, $this->report);
            $this->fail('A foreign run must not be reachable.');
        } catch (RuntimeException $exception) {
            $this->assertSame(404, $exception->getCode());
        }
    }

    public function test_a_run_with_a_file_passes_the_download_guard(): void
    {
        $run = $this->makeRun($this->report, ['file_path' => 'r/20241218-20241218.xlsx', 'file_size' => 10]);

        $this->assertSame($this->mediator, $this->mediator->assertRunHasFile($run));
    }

    public function test_a_run_without_a_file_is_a_404(): void
    {
        try {
            $this->mediator->assertRunHasFile($this->makeRun($this->report));
            $this->fail('A run with no artifact must not be downloadable.');
        } catch (RuntimeException $exception) {
            $this->assertSame(404, $exception->getCode());
        }
    }

    public function test_an_empty_file_path_counts_as_no_file(): void
    {
        $run = $this->makeRun($this->report, ['file_path' => '']);

        $this->expectException(RuntimeException::class);

        $this->mediator->assertRunHasFile($run);
    }

    /**
     * The guards return $this so a controller can chain ownership and existence in
     * one expression.
     */
    public function test_guards_are_chainable(): void
    {
        $run = $this->makeRun($this->report, ['file_path' => 'x.xlsx', 'file_size' => 1]);

        $this->assertSame(
            $this->mediator,
            $this->mediator
                ->assertOwnedBy($this->report, $this->owner)
                ->assertRunBelongsTo($run, $this->report)
                ->assertRunHasFile($run),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeRun(Report $report, array $attributes = []): ReportRun
    {
        return ReportRun::factory()->create(array_merge(
            ['report_id' => $report->getKey()],
            $attributes,
        ));
    }
}
