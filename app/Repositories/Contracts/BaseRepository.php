<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Model;
use App\Repositories\Traits\BaseRepositoryTrait;

/**
 * The base every repository extends.
 *
 * Writes stamp `created_by`/`updated_by`/`deleted_by` with the acting user by
 * default; pass the flags as false for system writes that must not be attributed.
 */
abstract class BaseRepository implements BaseRepositoryInterface
{
    use BaseRepositoryTrait;

    /** @var array<int, string> */
    protected array $selectItems = ['*'];

    /** @var array<int, string> */
    protected array $withItems = [];

    /**
     * The Eloquent model this repository is bound to. Concrete repositories inject
     * their own and call up.
     */
    public function __construct(protected Model $model) {}

    /**
     * The underlying model, for callers that need the class name — the Osmose
     * filter builder, for instance.
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload = [], bool $setCreateFlag = true, bool $setUpdateFlag = true, bool $dispatchEvents = true): Model
    {
        $model = $this->model->newInstance();
        $model->fill($payload);

        if ($setCreateFlag) {
            $this->setUserAction($model);
        }

        if ($setUpdateFlag) {
            $this->setUserAction($model, 'updated_by');
        }

        $dispatchEvents ? $model->saveOrFail() : $model->saveQuietly();
        $model->refresh();

        return $model;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(Model $model, array $payload, bool $setUpdateFlag = true, bool $dispatchEvents = true): void
    {
        $model->fill($payload);

        if ($setUpdateFlag) {
            $this->setUserAction($model, 'updated_by');
        }

        $dispatchEvents ? $model->saveOrFail() : $model->saveQuietly();
    }

    /**
     * Soft-deletes stamp `deleted_by` first so the audit trail survives the delete.
     */
    public function delete(Model $model, bool $setDeleteFlag = true): void
    {
        if ($setDeleteFlag) {
            $this->setUserAction($model, 'deleted_by');
            $model->saveQuietly();
        }

        $model->delete();
    }
}
