<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;

/**
 * The persistence contract every repository exposes.
 *
 * Services talk to Eloquent exclusively through this surface — a service that
 * calls `Model::where(...)` directly has bypassed the layer and is a defect.
 */
interface BaseRepositoryInterface
{
    /**
     * The Eloquent model this repository is bound to.
     */
    public function getModel(): Model;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload = [], bool $setCreateFlag = true, bool $setUpdateFlag = true, bool $dispatchEvents = true): Model;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(Model $model, array $payload, bool $setUpdateFlag = true, bool $dispatchEvents = true): void;

    public function delete(Model $model, bool $setDeleteFlag = true): void;

    /**
     * Find a single record by an arbitrary attribute, honouring the configured
     * select/with items.
     */
    public function findByAttribute(string $attribute, mixed $value): ?Model;

    /**
     * @param  array<int, mixed>  $values
     * @param  array<int, string>  $with
     * @return Collection<int, Model>
     */
    public function findInByAttribute(string $attribute, array $values, array $with = []): Collection;

    /**
     * @param  array<string, mixed>  $attributeWithValue  the lookup criteria
     * @param  array<string, mixed>  $attributes  extra attributes applied only on create
     */
    public function findOrCreate(array $attributeWithValue, array $attributes = []): Model;

    /**
     * @param  array<string, mixed>  $attributeWithValue
     * @param  array<string, mixed>  $attributes
     */
    public function updateOrCreate(array $attributeWithValue, array $attributes = []): Model;

    /**
     * Match records on a set of conditions; a scalar becomes `where`, an array becomes `whereIn`.
     *
     * @param  array<string, mixed>  $conditions
     * @return Collection<int, Model>
     */
    public function findByConditions(array $conditions): Collection;

    /**
     * @param  array<string, mixed>  $conditions
     */
    public function firstByConditions(array $conditions): ?Model;

    /**
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $payload
     * @return int  number of affected rows
     */
    public function updateByConditions(array $conditions, array $payload): int;

    /**
     * @param  array<string, mixed>  $conditions
     * @return int  number of affected rows
     */
    public function deleteByConditions(array $conditions): int;

    /**
     * @param  array<int, string>  $with
     * @return Collection<int, Model>
     */
    public function findAll(array $with = []): Collection;

    public function getLatestRecord(string $column = 'created_at'): ?Model;

    public function updateAttribute(Model $model, string $attribute, mixed $value): Model;

    public function changeStatus(Model $model, string $status, string $attribute = 'status', bool $setUpdateFlag = true): Model;

    /**
     * Stamp the acting user onto an auditing column (`created_by`/`updated_by`/`deleted_by`).
     */
    public function setUserAction(Model $model, string $attribute = 'created_by'): void;

    /**
     * The acting user's identifier, or null for unauthenticated/system writes.
     */
    public function getAuthor(): ?string;

    /**
     * @param  array<int, string>  $items
     */
    public function setSelectItems(array $items): self;

    /**
     * @return array<int, string>
     */
    public function getSelectItems(): array;

    /**
     * @param  array<int, string>  $items
     */
    public function setWithItems(array $items): self;

    /**
     * @return array<int, string>
     */
    public function getWithItems(): array;
}
