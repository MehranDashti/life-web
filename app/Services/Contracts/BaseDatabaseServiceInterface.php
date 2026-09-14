<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use Illuminate\Database\Eloquent\Model;
use App\DTO\Contracts\ToArrayDTOInterface;
use Illuminate\Http\Resources\Json\JsonResource;

interface BaseDatabaseServiceInterface
{
    /**
     * @param  class-string<JsonResource>  $resourceNameSpace
     */
    public function getView(Model $model, string $resourceNameSpace): JsonResource;

    /**
     * Apply a DTO's persistence payload to an existing record.
     */
    public function update(Model $model, ToArrayDTOInterface $dto): Model;
}
