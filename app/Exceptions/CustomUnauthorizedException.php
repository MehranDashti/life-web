<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Response;
use Mehrand\ApiExceptions\Contracts\ApiExceptionAbstract;

class CustomUnauthorizedException extends ApiExceptionAbstract
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
