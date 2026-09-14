<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use Illuminate\Database\Eloquent\Model;
use App\Services\Traits\BaseServiceTrait;
use App\DTO\Contracts\ToArrayDTOInterface;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Repositories\Contracts\BaseRepositoryInterface;

/**
 * The base every service extends.
 *
 * A service owns business logic and orchestration; it never touches Eloquent
 * directly and never shapes an HTTP response. Persistence goes through the
 * injected repository, guards go through an optional mediator.
 */
abstract class BaseService implements BaseDatabaseServiceInterface, BaseServiceInterface
{
    use BaseServiceTrait;

    /**
     * Every service takes exactly one repository, and picks up a mediator
     * automatically when the domain declares it has guards to enforce.
     */
    public function __construct(protected BaseRepositoryInterface $repository)
    {
        if ($this instanceof HasMediatorInterface) {
            $this->mediator = $this->mediatorClass();
        }
    }

    /**
     * @param  class-string<JsonResource>  $resourceNameSpace
     */
    public function getView(Model $model, string $resourceNameSpace): JsonResource
    {
        return new $resourceNameSpace($model);
    }

    /**
     * Apply a DTO's persistence payload to an existing record.
     *
     * The model is passed to the DTO so it can decide what to write based on the
     * record's current state.
     */
    public function update(Model $model, ToArrayDTOInterface $dto): Model
    {
        $this->repository->update($model, $dto->toArray($model));

        return $model;
    }
}
