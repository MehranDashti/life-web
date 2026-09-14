<?php

declare(strict_types=1);

namespace App\Http\Resources\Report;

use Illuminate\Http\Request;
use App\Models\Report\ReportRun;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReportRun
 */
class ReportRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_id' => $this->report_id,
            'status' => $this->status->value,
            'period_start' => isoDate($this->period_start),
            'period_end' => isoDate($this->period_end),
            'rows' => $this->rows,
            'total_matched' => $this->total_matched,

            // Wall-clock, the engine's own query time, and the export time are
            // reported separately: a single "duration" cannot tell a reader
            // whether a slow report is query-bound or export-bound.
            'duration_ms' => $this->duration_ms,
            'query_took_ms' => $this->query_took_ms,
            'export_ms' => $this->export_ms,

            'has_file' => $this->hasFile(),
            'file_size' => $this->file_size,
            'download_url' => $this->when(
                $this->hasFile(),
                fn (): string => route('v1.reports.runs.download', [
                    'report' => $this->report_id,
                    'run' => $this->id,
                ]),
            ),

            'attempts' => $this->attempts,
            'error' => $this->error,
            'delivered_at' => isoDate($this->delivered_at),
            'delivery_error' => $this->delivery_error,
            'created_at' => isoDate($this->created_at),
        ];
    }
}
