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
     * Write a report's histogram to a workbook and return its path and size.
     *
     * Layout, in order: a metadata block naming the report, its keywords and its
     * window — so a reviewer opening the file can tell what it is without consulting
     * the database — then the column headers, then one row per day.
     *
     * Every day in the window is written, including days with no posts. Skipping
     * them would undo the min_doc_count/extended_bounds care taken in the
     * aggregation and silently compress the time axis.
     *
     * There is deliberately no blank separator row: openspout skips a row with no
     * cell values, so a "spacer" would shift the header out of the frozen pane. The
     * frozen row is derived from the layout constants rather than hard-coded, so
     * adding a metadata row cannot break it.
     *
     * Dates are written in both calendars. Gregorian alone is unreadable to the
     * Persian audience this corpus serves; Jalali alone would be awkward to
     * cross-reference against the API.
     */
    public function write(Report $report, Carbon $from, Carbon $to, HistogramResult $histogram): WrittenFile
    {
        $disk = Storage::disk(self::DISK);
        $relativePath = $this->pathFor($report, $from, $to);

        $disk->makeDirectory(dirname($relativePath));
        $absolutePath = $disk->path($relativePath);

        $writer = new Writer;
        $writer->openToFile($absolutePath);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('histogram');

        $sheet->setSheetView(
            (new SheetView)->setRightToLeft(true)->setFreezeRow(self::HEADER_ROW + 1),
        );
        $sheet->setColumnWidth(22, 1, 2, 3);

        $bold = (new Style)->setFontBold();
        $timezone = (string) config('search.histogram.timezone', 'Asia/Tehran');

        $this->writeMeta($writer, $bold, trans('messages.report_sheet_title'), $report->name);
        $this->writeMeta($writer, $bold, trans('messages.report_sheet_keywords'), implode('، ', $report->keywords));
        $this->writeMeta($writer, $bold, trans('messages.report_sheet_period'), $report->period->label());
        $this->writeMeta($writer, $bold, trans('messages.report_sheet_from'), $this->bothCalendars($from, $timezone));
        $this->writeMeta($writer, $bold, trans('messages.report_sheet_to'), $this->bothCalendars($to, $timezone));
        $this->writeMeta($writer, $bold, trans('messages.report_sheet_total'), (string) $histogram->total);
        $this->writeMeta($writer, $bold, trans('messages.report_sheet_generated_at'), $this->bothCalendars(Carbon::now(), $timezone));

        $writer->addRow(Row::fromValues([
            trans('messages.report_sheet_date_gregorian'),
            trans('messages.report_sheet_date_jalali'),
            trans('messages.report_sheet_count'),
        ], $bold));

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

    /**
     * One metadata row: a bold label against its value.
     */
    private function writeMeta(Writer $writer, Style $bold, string $label, string $value): void
    {
        $writer->addRow(Row::fromValuesWithStyles([$label, $value], null, [1 => $bold]));
    }

    /**
     * A moment rendered in both calendars, localised to the report timezone.
     */
    private function bothCalendars(Carbon $moment, string $timezone): string
    {
        $local = $moment->copy()->timezone($timezone);

        return $local->format('Y-m-d H:i').' — '.Jalalian::fromCarbon($local)->format('Y/m/d H:i');
    }
}
