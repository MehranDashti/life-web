<?php

declare(strict_types=1);

namespace App\Models\Report;

use App\Enums\ReportRunStatus;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\Report\ReportRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One execution of a report over one window.
 *
 * Rows are the audit trail the task's observability and idempotency requirements
 * rest on: what window, how long, how many rows, which file, and why it failed.
 *
 * @property string $id
 * @property string $report_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property ReportRunStatus $status
 * @property int $rows
 * @property int $total_matched
 * @property string|null $file_path
 * @property int|null $file_size
 * @property int|null $duration_ms
 * @property int|null $query_took_ms
 * @property int|null $export_ms
 * @property int $attempts
 * @property string|null $error
 * @property Carbon|null $delivered_at
 * @property string|null $delivery_error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Report $report
 */
class ReportRun extends Model
{
    /** @use HasFactory<ReportRunFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'report_id',
        'period_start',
        'period_end',
        'status',
        'rows',
        'total_matched',
        'file_path',
        'file_size',
        'duration_ms',
        'query_took_ms',
        'export_ms',
        'attempts',
        'error',
        'delivered_at',
        'delivery_error',
    ];

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function hasFile(): bool
    {
        return $this->file_path !== null && $this->file_path !== '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReportRunStatus::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'delivered_at' => 'datetime',
            'rows' => 'integer',
            'total_matched' => 'integer',
            'file_size' => 'integer',
            'duration_ms' => 'integer',
            'query_took_ms' => 'integer',
            'export_ms' => 'integer',
            'attempts' => 'integer',
        ];
    }
}
