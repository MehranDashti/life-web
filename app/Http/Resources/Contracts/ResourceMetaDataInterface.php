<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Implemented by resources that expose related data, so relation shaping lives in
 * one declared method rather than scattered `when()` calls inside `toArray()`.
 */
interface ResourceMetaDataInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getMetaData(Model $model): array;
}
