<?php

declare(strict_types=1);

namespace App\Http\Filters\Report;

use Agog\Osmose\Library\OsmoseFilter;
use Illuminate\Database\Eloquent\Builder;
use Agog\Osmose\Library\Services\Contracts\OsmoseFilterInterface;

/**
 * Query-string filters for the report list.
 *
 * Everything declared here narrows WITHIN the caller's own rows — ownership is
 * applied separately by the service through addListConditions(), which a client
 * parameter cannot reach.
 */
class ReportFilter extends OsmoseFilter implements OsmoseFilterInterface
{
    /**
     * @return array<string, callable>
     */
    public function residue(): array
    {
        return [
            'name' => static fn (Builder $query, mixed $value): Builder => $query->where('name', 'like', '%'.$value.'%'),
            'period' => static fn (Builder $query, mixed $value): Builder => $query->where('period', '=', $value),
            'status' => static fn (Builder $query, mixed $value): Builder => $query->where('status', '=', $value),
            'keyword' => static fn (Builder $query, mixed $value): Builder => $query->whereJsonContains('keywords', $value),
        ];
    }
}
