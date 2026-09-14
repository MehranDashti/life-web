<?php

declare(strict_types=1);

namespace App\Mediators\Contracts;

use RuntimeException;
use Illuminate\Http\Response;
use Illuminate\Database\Eloquent\Model;
use App\Repositories\Contracts\BaseRepositoryInterface;

/**
 * The base every mediator extends.
 *
 * Guards throw a RuntimeException carrying an HTTP status code; the exception
 * renderer wired in bootstrap/app.php turns that into the standard error envelope.
 */
abstract class BaseMediator implements BaseMediatorInterface
{
    private ?BaseRepositoryInterface $repository = null;

    public function __construct()
    {
        if ($this instanceof HasRepositoryInterface) {
            $this->setRepository($this->repositoryClass());
        }
    }

    public function setRepository(BaseRepositoryInterface $repository): self
    {
        $this->repository = $repository;

        return $this;
    }

    public function getRepository(): BaseRepositoryInterface
    {
        if (! $this->repository instanceof BaseRepositoryInterface) {
            throw new RuntimeException(static::class.' has no repository; implement HasRepositoryInterface.');
        }

        return $this->repository;
    }

    /**
     * @param  array<int, string>  $assertValues
     */
    protected function checkAssertHasValue(string $value, array $assertValues, string $message): self
    {
        if (! in_array($value, $assertValues, true)) {
            throw new RuntimeException($message, Response::HTTP_NOT_ACCEPTABLE);
        }

        return $this;
    }

    /**
     * Guard that a record belongs to the acting user.
     *
     * Ownership is a data-integrity rule and is always enforced, even though
     * role/permission authorization is out of scope for this project.
     */
    protected function checkOwnership(Model $model, ?string $userId, string $foreignKey = 'user_id'): self
    {
        if ($userId === null || $model->getAttribute($foreignKey) !== $userId) {
            throw new RuntimeException(trans('messages.resource_not_owned'), Response::HTTP_FORBIDDEN);
        }

        return $this;
    }

    /**
     * @param  class-string<Model>  $expectedModel
     */
    protected function checkModelExists(?Model $model, string $expectedModel): self
    {
        if (! $model instanceof $expectedModel) {
            throw new RuntimeException(trans('messages.model_not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this;
    }
}
