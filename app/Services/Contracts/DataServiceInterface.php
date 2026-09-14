<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use Agog\Osmose\Library\OsmoseFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Implemented by services backing a filterable, paginated list endpoint.
 *
 * The return shape is fixed across the whole API:
 * `['list' => [...], 'pagination' => ['total', 'current', 'page_size']]`.
 */
interface DataServiceInterface
{
    /**
     * @param  class-string<JsonResource>|null  $resource
     * @param  array<string, mixed>  $conditions  server-side constraints the client cannot override
     * @return array{list: mixed, pagination: array{total: int, current: int, page_size: int}}
     */
    public function getFilter(OsmoseFilter $filter, ?Model $model = null, ?string $resource = null, array $conditions = []): array;
}
