<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use RuntimeException;
use App\Models\User\User;
use App\Mail\ReportReadyMail;
use App\Models\Report\Report;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The task waives full email delivery, but the mailable still has to be correct:
 * addressed to the owner, carrying the workbook, and readable.
 */
class ReportMailTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('reports');

        $search = $this->fakeSearch();
        $search->bulkIndex([[
            'id' => '1',
            'title' => 'آلودگی هوای تهران',
            'lead' => '',
            'content' => '',
            'published_at' => '2024-12-18T08:00:00Z',
        ]]);

        $this->owner = User::factory()->create();
        Passport::actingAs($this->owner);
        $this->report = Report::factory()
            ->withKeywords(['تهران'])
            ->create(['user_id' => $this->owner->id, 'name' => 'آلودگی هوای تهران']);
    }

    public function test_the_report_is_mailed_to_its_owner(): void
    {
        $this->generate();

        Mail::assertSent(
            ReportReadyMail::class,
            fn (ReportReadyMail $mail): bool => $mail->hasTo($this->owner->email),
        );
    }

    public function test_the_subject_names_the_report(): void
    {
        $this->generate();

        Mail::assertSent(ReportReadyMail::class, fn (ReportReadyMail $mail): bool => str_contains($mail->envelope()->subject, $this->report->name));
    }

    public function test_the_subject_is_translated_not_a_raw_key(): void
    {
        $this->generate();

        Mail::assertSent(ReportReadyMail::class, fn (ReportReadyMail $mail): bool => ! str_contains($mail->envelope()->subject, 'messages.'));
    }

    public function test_the_workbook_is_attached(): void
    {
        $this->generate();

        Mail::assertSent(ReportReadyMail::class, function (ReportReadyMail $mail): bool {
            $attachments = $mail->attachments();

            return count($attachments) === 1
                && str_ends_with((string) $mail->run->file_path, '.xlsx');
        });
    }

    public function test_the_body_renders_without_a_missing_translation(): void
    {
        $this->generate();

        Mail::assertSent(ReportReadyMail::class, function (ReportReadyMail $mail): bool {
            $rendered = $mail->render();

            return ! str_contains($rendered, 'messages.')
                && str_contains($rendered, $this->report->name);
        });
    }

    public function test_a_run_with_no_file_carries_no_attachment(): void
    {
        $run = $this->report->runs()->create([
            'period_start' => now()->subDay()->startOfDay(),
            'period_end' => now()->subDay()->endOfDay(),
            'status' => 'succeeded',
        ]);

        $this->assertSame([], (new ReportReadyMail($this->report, $run))->attachments());
    }

    public function test_delivery_is_recorded_on_the_run(): void
    {
        $this->generate();

        $this->assertNotNull($this->report->runs()->firstOrFail()->delivered_at);
        $this->assertNull($this->report->runs()->firstOrFail()->delivery_error);
    }

    /**
     * Generation and delivery are separate steps: a mail failure must not discard
     * a correctly generated report or cause the histogram to be recomputed.
     */
    public function test_a_delivery_failure_leaves_the_run_succeeded_and_the_file_on_disk(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

        $this->generate();

        $run = $this->report->runs()->firstOrFail();

        $this->assertSame('succeeded', $run->status->value);
        $this->assertTrue($run->hasFile());
        Storage::disk('reports')->assertExists((string) $run->file_path);
        $this->assertStringContainsString('smtp down', (string) $run->delivery_error);
        $this->assertNull($run->delivered_at);
    }

    public function test_only_one_email_is_sent_for_a_repeated_window(): void
    {
        $this->generate();
        $this->generate();

        Mail::assertSentCount(1);
    }

    private function generate(): void
    {
        $this->postJson("/api/v1/reports/{$this->report->id}/run", [
            'from' => '2024-12-18',
            'to' => '2024-12-19',
        ])->assertOk();
    }
}
