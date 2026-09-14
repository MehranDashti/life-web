<?php

declare(strict_types=1);

namespace App\DTO\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A DTO that can render itself as the persistence payload a repository writes.
 *
 * The optional model is the record being updated, for DTOs whose output depends
 * on the current state (e.g. "keep the existing value when the field is absent").
 */
interface ToArrayDTOInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(?Model $model = null): array;
}
