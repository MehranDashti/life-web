<?php

declare(strict_types=1);

namespace App\DTO\Contracts;

/**
 * A DTO that can be hydrated from a plain array — used by console commands and
 * queued jobs, which have no FormRequest to build from.
 */
interface FromArrayDTOInterface
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function fromArray(array $payload): static;
}
