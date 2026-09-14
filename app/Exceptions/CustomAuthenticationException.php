<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Response;
use Mehrand\ApiExceptions\Contracts\ApiExceptionAbstract;

/**
 * A missing or invalid bearer token is 401, not the package default of 403 —
 * the caller has not authenticated at all.
 */
class CustomAuthenticationException extends ApiExceptionAbstract
{
    public function setCode(mixed $code = null): self
    {
        $this->code = Response::HTTP_UNAUTHORIZED;

        return $this;
    }

    public function setMessage(mixed $message = null): self
    {
        $this->message = trans('messages.user_is_unauthenticated');

        return $this;
    }
}
