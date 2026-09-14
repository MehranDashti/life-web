<?php

declare(strict_types=1);

namespace App\Services\Report;

use Morilog\Jalali\Jalalian;
use App\Models\Report\Report;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Style\Style;
use App\Services\Report\Data\WrittenFile;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use App\Adapters\Contracts\Data\HistogramResult;

/**
 * Writes a report's daily histogram to an XLSX workbook.
 *
 * Rows are streamed one at a time rather than assembled in memory. A daily
 * histogram is small — seven rows for a week — so this is not about today's
 * numbers: it is about the answer to "what if the user's range is very large"
 * staying true at the export step as well as at the query step.
 *
 * openspout is used rather than PhpSpreadsheet precisely because PhpSpreadsheet
 * builds the whole workbook in RAM. That choice is part of the scalability answer,
 * not an incidental dependency pick.
 */
final class HistogramExcelWriter
{
    private const string DISK = 'reports';

    /** Metadata rows written before the column headers. */
    private const int META_ROWS = 7;

    /** 1-indexed sheet row the column headers land on. */
    private const HEADER_ROW = self::META_ROWS + 1;

    /**
     * Gregorian dates are unreadable to the Persian audience this corpus serves,
     * and Jalali alone would be awkward to cross-reference with the API. Both are
     * written.
     */
    public function write(Report $report, Carbon $from, Carbon $to, HistogramResult $histogram): WrittenFile
    {
        $disk = Storage::disk(self::DISK);
        $relativePath = $this->pathFor($report, $from, $to);

        // openspout writes to a real filesystem path, so the directory has to
        // exist even on the local driver.
        $disk->makeDirectory(dirname($relativePath));
        $absolutePath = $disk->path($relativePath);

        $writer = new Writer;
        $writer->openToFile($absolutePath);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('histogram');

        // Persian-first output: RTL, with the header frozen so a long window
        // stays readable while scrolling.
        // Freeze everything above the first data row, so a long window stays
        // readable while scrolling. Derived from the layout constants rather than
        // hard-coded, so adding a metadata row cannot silently break the freeze.
        $sheet->setSheetView(
            (new SheetView)->setRightToLeft(true)->setFreezeRow(self::HEADER_ROW + 1),
        );
        $sheet->setColumnWidth(22, 1, 2, 3);

        $bold = (new Style)->setFontBold();
        $timezone = (string) config('search.histogram.timezone', 'Asia/Tehran');

        // A reviewer opening the file must be able to tell which report it is and
        // what window it covers, without consulting the database.
        $this->writeMeta($writer, $bold, trans('messages.report_sheet_title'), $report->name);
        $this->writeMeta($writer, $bold, trans('messages.report_sheet_keywords'), implode('، ', $report->keywords));
        $this->writeMeta($writer, $bold, trans('messages.report_sheet_period'), $report->period->label());
        $this->writeMeta($writer, $bold, trans('messages.report_sheet_from'), $this->bothCalendars($from, $timezone));
        $this->writeMeta($writer, $bold, trans('messages.report_sheet_to'), $this->bothCalendars($to, $timezone));
        $this->writeMeta($writer, $bold, trans('messages.report_sheet_total'), (string) $histogram->total);
        $this->writeMeta($writer, $bold, trans('messages.report_sheet_generated_at'), $this->bothCalendars(Carbon::now(), $timezone));

        // No blank separator row: openspout skips a row with no cell values, so a
        // "spacer" would silently shift the header off the frozen pane. The bold
        // header is the separation.
        $writer->addRow(Row::fromValues([
            trans('messages.report_sheet_date_gregorian'),
            trans('messages.report_sheet_date_jalali'),
            trans('messages.report_sheet_count'),
        ], $bold));

        // Every day in the window, including zeros. Skipping empty days would
        // undo the min_doc_count/extended_bounds care taken in the aggregation and
        // silently compress the time axis.
        foreach ($histogram->buckets as $bucket) {
            $local = $bucket->date->copy()->timezone($timezone);

            $writer->addRow(Row::fromValues([
                $local->format('Y-m-d'),
                Jalalian::fromCarbon($local)->format('Y/m/d'),
                $bucket->count,
            ]));
        }

        $writer->close();

        clearstatcache(true, $absolutePath);

        return new WrittenFile($relativePath, (int) $disk->size($relativePath));
    }

    /**
     * Deterministic, namespaced by report: a re-run of the same window overwrites
     * rather than accumulating orphans, and retention for one report is a
     * directory delete.
     */
    public function pathFor(Report $report, Carbon $from, Carbon $to): string
    {
        return sprintf(
            '%s/%s-%s.xlsx',
            $report->getKey(),
            $from->format('Ymd'),
            $to->format('Ymd'),
        );
    }

    private function writeMeta(Writer $writer, Style $bold, string $label, string $value): void
    {
        $writer->addRow(Row::fromValuesWithStyles([$label, $value], null, [1 => $bold]));
    }

    private function bothCalendars(Carbon $moment, string $timezone): string
    {
        $local = $moment->copy()->timezone($timezone);

        return $local->format('Y-m-d H:i').' — '.Jalalian::fromCarbon($local)->format('Y/m/d H:i');
    }
}
