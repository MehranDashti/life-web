<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use ZipArchive;
use Tests\TestCase;
use App\Models\User\User;
use App\Models\Report\Report;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use App\Services\Report\HistogramExcelWriter;
use App\Adapters\Contracts\Data\HistogramBucket;
use App\Adapters\Contracts\Data\HistogramResult;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The Excel file is the deliverable the task actually asks for, so its contents
 * are asserted directly rather than inferred from "a file exists".
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->report = Report::factory()
            ->withKeywords(['تهران', 'آلودگی'])
            ->create(['user_id' => $user->id, 'name' => 'آلودگی هوای تهران']);
    }

    protected function tearDown(): void
    {
        $disk = Storage::disk('reports');

        if ($disk->exists((string) $this->report->id)) {
            $disk->deleteDirectory((string) $this->report->id);
        }

        parent::tearDown();
    }

    public function test_the_workbook_contains_one_row_per_day_including_zeros(): void
    {
        $rows = $this->writeAndRead($this->histogram([4, 0, 11]));
        $data = array_slice($rows, 8);

        $this->assertCount(3, $data);
        $this->assertSame(['2024-12-18', '1403/09/28', '4'], $data[0]);
        $this->assertSame(['2024-12-19', '1403/09/29', '0'], $data[1], 'An empty day must still be a row.');
        $this->assertSame(['2024-12-20', '1403/09/30', '11'], $data[2]);
    }

    public function test_the_metadata_block_identifies_the_report(): void
    {
        $rows = $this->writeAndRead($this->histogram([1, 1, 1]));
        $flat = implode(' | ', array_map(static fn (array $r): string => implode(' ', $r), array_slice($rows, 0, 7)));

        $this->assertStringContainsString('آلودگی هوای تهران', $flat);
        $this->assertStringContainsString('تهران، آلودگی', $flat);
        $this->assertStringContainsString(trans('messages.period_daily'), $flat);
    }

    public function test_dates_are_written_in_both_gregorian_and_jalali(): void
    {
        $rows = $this->writeAndRead($this->histogram([1]));

        $this->assertSame(
            [trans('messages.report_sheet_date_gregorian'),
                trans('messages.report_sheet_date_jalali'),
                trans('messages.report_sheet_count')],
            $rows[7],
        );
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/\d{2}$#', $rows[8][1]);
    }

    public function test_the_sheet_is_right_to_left_with_a_frozen_header(): void
    {
        $xml = $this->sheetXml($this->histogram([1]));

        $this->assertMatchesRegularExpression('/rightToLeft="(1|true)"/', $xml);
        // The header sits on row 8, so everything through row 8 must be frozen.
        $this->assertStringContainsString('ySplit="8"', $xml);
    }

    public function test_the_path_is_deterministic_so_a_rerun_overwrites(): void
    {
        $writer = app(HistogramExcelWriter::class);
        $from = Carbon::parse('2024-12-18T00:00:00Z');
        $to = Carbon::parse('2024-12-20T23:59:59Z');

        $first = $writer->write($this->report, $from, $to, $this->histogram([1, 1, 1]));
        $second = $writer->write($this->report, $from, $to, $this->histogram([2, 2, 2]));

        $this->assertSame($first->path, $second->path);
        $this->assertCount(
            1,
            Storage::disk('reports')->files((string) $this->report->id),
        );
    }

    public function test_files_are_namespaced_by_report(): void
    {
        $path = app(HistogramExcelWriter::class)->pathFor(
            $this->report,
            Carbon::parse('2024-12-18'),
            Carbon::parse('2024-12-20'),
        );

        $this->assertSame("{$this->report->id}/20241218-20241220.xlsx", $path);
    }

    /**
     * The streaming claim has to hold, or the answer to "what if the range is very
     * large" is untrue at the export step.
     */
    public function test_memory_does_not_grow_with_the_length_of_the_window(): void
    {
        $writer = app(HistogramExcelWriter::class);
        $from = Carbon::parse('2024-01-01T00:00:00Z');

        $short = $this->measure(fn () => $writer->write(
            $this->report, $from, $from->copy()->addDays(6), $this->sequentialHistogram($from, 7),
        ));

        $long = $this->measure(fn () => $writer->write(
            $this->report, $from, $from->copy()->addDays(364), $this->sequentialHistogram($from, 365),
        ));

        $this->assertLessThan(
            8 * 1024 * 1024,
            $long - $short,
            'A 365-day window should not cost meaningfully more memory than a 7-day one.',
        );
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function measure(callable $callback): int
    {
        $before = memory_get_usage();
        $callback();

        return memory_get_usage() - $before;
    }

    /**
     * @param  array<int, int>  $counts
     */
    private function histogram(array $counts): HistogramResult
    {
        $start = Carbon::parse('2024-12-18T00:00:00', 'Asia/Tehran');

        $buckets = [];

        foreach ($counts as $index => $count) {
            $buckets[] = new HistogramBucket($start->copy()->addDays($index), $count);
        }

        return new HistogramResult($buckets, array_sum($counts), 3);
    }

    private function sequentialHistogram(Carbon $from, int $days): HistogramResult
    {
        $buckets = [];

        for ($i = 0; $i < $days; $i++) {
            $buckets[] = new HistogramBucket($from->copy()->addDays($i), $i);
        }

        return new HistogramResult($buckets, $days, 1);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function writeAndRead(HistogramResult $histogram): array
    {
        $xml = $this->sheetXml($histogram);
        $rows = [];

        preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $xml, $matches);

        foreach ($matches[1] as $row) {
            preg_match_all('/<c[^>]*>(.*?)<\/c>/s', $row, $cells);
            $rows[] = array_map(strip_tags(...), $cells[1]);
        }

        return $rows;
    }

    private function sheetXml(HistogramResult $histogram): string
    {
        $written = app(HistogramExcelWriter::class)->write(
            $this->report,
            Carbon::parse('2024-12-18T00:00:00Z'),
            Carbon::parse('2024-12-20T23:59:59Z'),
            $histogram,
        );

        $zip = new ZipArchive;
        $zip->open(Storage::disk('reports')->path($written->path));
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        return $xml;
    }
}
