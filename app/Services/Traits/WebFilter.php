<?php

declare(strict_types=1);

namespace App\Services\Traits;

use LogicException;
use Agog\Osmose\Library\OsmoseFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A fluent wrapper around Osmose filtering and pagination, used by every list
 * endpoint so the response shape is identical across the API.
 *
 * Usage inside a service:
 *
 *   return $this->filter($filter, $this->repository->getModel())
 *       ->with(['user'])
 *       ->addListConditions(['user_id' => $userId])   // server-side, not overridable
 *       ->sortModel($this->queryInfo()['sort'])
 *       ->orderBy('created_at', 'DESC')
 *       ->paginate()
 *       ->renderFilter(ReportResource::class);
 *
 * Query params honoured: `page_size`/`pageSize`, `current`/`page`, and `sorter`
 * (a JSON object of `{column: "ascend"|"descend"}`).
 */
trait WebFilter
{
    /** @var Builder<Model>|LengthAwarePaginator<int, Model> */
    private Builder|LengthAwarePaginator $builder;

    /**
     * @param  array<int, string>  $relations
     */
    public function with(array $relations): self
    {
        $this->queryBuilder()->with($relations);

        return $this;
    }

    /**
     * @param  array<int, string>  $columns
     */
    public function select(array $columns): self
    {
        $this->queryBuilder()->select($columns);

        return $this;
    }

    /**
     * Add a sort. Applied after any client-supplied sorter, so it acts as a
     * tiebreaker rather than overriding the caller.
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->queryBuilder()->orderBy($column, $direction);

        return $this;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    public function whereIn(string $column, array $values): self
    {
        $this->queryBuilder()->whereIn($column, $values);

        return $this;
    }

    /**
     * Add a constraint, passing through to the underlying builder.
     */
    public function where(mixed $column, mixed $operator = null, mixed $value = null): self
    {
        $this->queryBuilder()->where($column, $operator, $value);

        return $this;
    }

    /**
     * Server-side constraints the client cannot override — ownership scoping above
     * all. Client filters narrow WITHIN this set; they can never widen it.
     *
     * @param  array<string, mixed>  $conditions
     */
    public function addListConditions(array $conditions = []): self
    {
        foreach ($conditions as $field => $value) {
            is_array($value) ? $this->whereIn($field, $value) : $this->where($field, $value);
        }

        return $this;
    }

    /**
     * Start a query from an Osmose filter. Every chained call below refines it.
     */
    protected function filter(OsmoseFilter $filter, Model $model): self
    {
        $this->builder = $filter->sieve($model::class);

        return $this;
    }

    /**
     * Close the query and page it.
     *
     * After this the builder is a paginator, so no further constraints can be added
     * — renderFilter() is the only legal next step.
     */
    protected function paginate(?int $pagination = null): self
    {
        $queryInfo = $this->queryInfo();

        $this->builder = $this->queryBuilder()->paginate(
            perPage: $pagination ?? $queryInfo['page_size'],
            page: $queryInfo['current'],
        );

        return $this;
    }

    /**
     * @param  array<string, string>  $sort
     */
    protected function sortModel(array $sort): self
    {
        foreach ($sort as $column => $direction) {
            $this->orderBy($column, $this->getSortMap()[$direction] ?? 'ASC');
        }

        return $this;
    }

    /**
     * The canonical list envelope. Every list endpoint returns exactly this shape.
     *
     * The collection is resolved to a plain array rather than left as a
     * JsonResource collection, which is not safely serialisable: cached and read
     * back it becomes __PHP_Incomplete_Class, so the endpoint returns garbage as
     * soon as the query cache is warm. Resolving produces identical JSON and is
     * cacheable.
     *
     * @param  class-string<JsonResource>  $resource
     * @return array{list: mixed, pagination: array{total: int, current: int, page_size: int}}
     */
    protected function renderFilter(string $resource): array
    {
        $paginator = $this->builder;

        if (! $paginator instanceof LengthAwarePaginator) {
            throw new LogicException('renderFilter() requires paginate() to have run first.');
        }

        return [
            'list' => $resource::collection($paginator->getCollection())->resolve(),
            'pagination' => [
                'total' => $paginator->total(),
                'current' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
            ],
        ];
    }

    /**
     * @return array{page_size: int, current: int, sort: array<string, string>}
     */
    protected function queryInfo(): array
    {
        $pageSize = (int) (request()->query('page_size') ?? request()->query('pageSize') ?? 0);
        $current = (int) (request()->query('current') ?? request()->query('page') ?? 1);

        $sorter = request()->query('sorter');
        $sort = is_string($sorter) ? json_decode($sorter, true) : null;

        return [
            'page_size' => $pageSize > 0 ? min($pageSize, $this->getMaxPageSize()) : $this->getServicePaginate(),
            'current' => max($current, 1),
            'sort' => is_array($sort) ? $sort : [],
        ];
    }

    /**
     * Hard ceiling on client-controlled page size. An unbounded `page_size` is the
     * cheapest way for a single request to exhaust a worker.
     */
    protected function getMaxPageSize(): int
    {
        return 200;
    }

    /**
     * @return Builder<Model>
     */
    private function queryBuilder(): Builder
    {
        if (! $this->builder instanceof Builder) {
            throw new LogicException('The query has already been paginated; no further constraints can be added.');
        }

        return $this->builder;
    }

    /**
     * @return array<string, string>
     */
    private function getSortMap(): array
    {
        return ['ascend' => 'ASC', 'descend' => 'DESC'];
    }
}
