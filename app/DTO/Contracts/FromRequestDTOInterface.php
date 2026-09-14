<?php

declare(strict_types=1);

namespace App\DTO\Contracts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A DTO that can be hydrated from a validated FormRequest.
 *
 * Controllers build DTOs through this contract; a raw `$request->all()` must
 * never reach a service or a repository.
 */
interface FromRequestDTOInterface
{
    public function fromRequest(FormRequest $request): static;
}
