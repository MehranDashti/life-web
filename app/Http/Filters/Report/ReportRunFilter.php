<?php

declare(strict_types=1);

namespace App\Http\Filters\Report;

use Agog\Osmose\Library\OsmoseFilter;
use Illuminate\Database\Eloquent\Builder;
use Agog\Osmose\Library\Services\Contracts\OsmoseFilterInterface;

class ReportRunFilter extends OsmoseFilter implements OsmoseFilterInterface
{
    /**
     * @return array<string, callable>
     */
    public function residue(): array
    {
        return [
            'status' => static fn (Builder $query, mixed $value): Builder => $query->where('status', '=', $value),
        ];
    }
}
