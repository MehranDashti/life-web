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
    /**
     * Build a populated instance from an already-validated request.
     *
     * Returns `static` rather than `self` so a concrete DTO keeps its own type at
     * the call site instead of widening to this interface.
     */
    public function fromRequest(FormRequest $request): static;
}
