<?php

declare(strict_types=1);

namespace App\Repositories\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The generic query surface shared by every repository.
 *
 * Concrete repositories stay empty unless they need a genuinely domain-specific
 * query; everything reusable lives here.
 */
trait BaseRepositoryTrait
{
    public function findByAttribute(string $attribute, mixed $value): ?Model
    {
        return $this->newQuery()
            ->where($attribute, $value)
            ->select($this->getSelectItems())
            ->with($this->getWithItems())
            ->first();
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<int, string>  $with
     * @return Collection<int, Model>
     */
    public function findInByAttribute(string $attribute, array $values, array $with = []): Collection
    {
        return $this->newQuery()
            ->whereIn($attribute, $values)
            ->with($with !== [] ? $with : $this->getWithItems())
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributeWithValue
     * @param  array<string, mixed>  $attributes
     */
    public function findOrCreate(array $attributeWithValue, array $attributes = []): Model
    {
        $model = $this->firstByConditions($attributeWithValue);

        return $model ?? $this->create(array_merge($attributes, $attributeWithValue));
    }

    /**
     * @param  array<string, mixed>  $attributeWithValue
     * @param  array<string, mixed>  $attributes
     */
    public function updateOrCreate(array $attributeWithValue, array $attributes = []): Model
    {
        $model = $this->firstByConditions($attributeWithValue);
        $payload = array_merge($attributes, $attributeWithValue);

        if (! $model instanceof Model) {
            return $this->create($payload);
        }

        $this->update($model, $payload);

        return $model;
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @return Collection<int, Model>
     */
    public function findByConditions(array $conditions): Collection
    {
        return $this->applyConditions($this->newQuery(), $conditions)
            ->with($this->getWithItems())
            ->get();
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    public function firstByConditions(array $conditions): ?Model
    {
        return $this->applyConditions($this->newQuery(), $conditions)
            ->with($this->getWithItems())
            ->first();
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $payload
     */
    public function updateByConditions(array $conditions, array $payload): int
    {
        return $this->applyConditions($this->newQuery(), $conditions)->update($payload);
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    public function deleteByConditions(array $conditions): int
    {
        return $this->applyConditions($this->newQuery(), $conditions)->delete();
    }

    /**
     * @param  array<int, string>  $with
     * @return Collection<int, Model>
     */
    public function findAll(array $with = []): Collection
    {
        return $this->newQuery()
            ->with($with !== [] ? $with : $this->getWithItems())
            ->get();
    }

    public function getLatestRecord(string $column = 'created_at'): ?Model
    {
        return $this->newQuery()->latest($column)->first();
    }

    public function updateAttribute(Model $model, string $attribute, mixed $value): Model
    {
        $model->setAttribute($attribute, $value);
        $model->save();

        return $model;
    }

    public function changeStatus(Model $model, string $status, string $attribute = 'status', bool $setUpdateFlag = true): Model
    {
        $model->setAttribute($attribute, $status);

        if ($setUpdateFlag) {
            $this->setUserAction($model, 'updated_by');
        }

        $model->save();

        return $model;
    }

    public function setUserAction(Model $model, string $attribute = 'created_by'): void
    {
        $model->setAttribute($attribute, $this->getAuthor());
    }

    /**
     * The acting user's UUID, or null for unauthenticated/system writes (seeders,
     * scheduled jobs). Null is a legitimate value — the blamable columns are nullable.
     */
    public function getAuthor(): ?string
    {
        $id = Auth::guard('api')->id();

        return is_scalar($id) ? (string) $id : null;
    }

    /**
     * @param  array<int, string>  $items
     */
    public function setSelectItems(array $items): self
    {
        $this->selectItems = $items;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getSelectItems(): array
    {
        return $this->selectItems;
    }

    /**
     * @param  array<int, string>  $items
     */
    public function setWithItems(array $items): self
    {
        $this->withItems = $items;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getWithItems(): array
    {
        return $this->withItems;
    }

    /**
     * A scalar condition becomes `where`, an array becomes `whereIn`, null becomes `whereNull`.
     *
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $conditions
     * @return Builder<Model>
     */
    protected function applyConditions(Builder $query, array $conditions): Builder
    {
        foreach ($conditions as $column => $value) {
            match (true) {
                is_array($value) => $query->whereIn($column, $value),
                $value === null => $query->whereNull($column),
                default => $query->where($column, $value),
            };
        }

        return $query;
    }

    /**
     * @return Builder<Model>
     */
    protected function newQuery(): Builder
    {
        return $this->model->newQuery();
    }
}
