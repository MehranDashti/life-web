<?php

declare(strict_types=1);

namespace Database\Factories\Report;

use App\Models\Report\Report;
use App\Enums\ReportRunStatus;
use Illuminate\Support\Carbon;
use App\Models\Report\ReportRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportRun>
 */
class ReportRunFactory extends Factory
{
    protected $model = ReportRun::class;

    public function definition(): array
    {
        $start = Carbon::now()->subDay()->startOfDay();

        return [
            'report_id' => Report::factory(),
            'period_start' => $start,
            'period_end' => $start->copy()->endOfDay(),
            'status' => ReportRunStatus::Succeeded,
            'rows' => 1,
            'total_matched' => 0,
            'file_path' => null,
            'file_size' => null,
            'duration_ms' => 10,
            'query_took_ms' => 2,
            'export_ms' => 3,
            'attempts' => 1,
            'error' => null,
            'delivered_at' => null,
            'delivery_error' => null,
        ];
    }

    public function failed(string $error = 'boom'): self
    {
        return $this->state(fn (): array => [
            'status' => ReportRunStatus::Failed,
            'error' => $error,
        ]);
    }

    public function withFile(string $path = 'reports/example.xlsx', int $size = 4096): self
    {
        return $this->state(fn (): array => ['file_path' => $path, 'file_size' => $size]);
    }
}
