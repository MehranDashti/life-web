<?php

declare(strict_types=1);

namespace App\Http\Resources\Report;

use Illuminate\Http\Request;
use App\Models\Report\Report;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Report
 */
class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'period' => $this->period->value,
            'period_label' => $this->period->label(),
            'keywords' => $this->keywords,
            'news_agency_ids' => $this->news_agency_ids,
            'match_all_keywords' => $this->match_all_keywords,
            'status' => $this->status->value,
            'consecutive_failures' => $this->consecutive_failures,
            'last_run_at' => isoDate($this->last_run_at),
            'next_run_at' => isoDate($this->next_run_at),
            'created_at' => isoDate($this->created_at),
            'updated_at' => isoDate($this->updated_at),
        ];
    }
}
