<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Response;
use Mehrand\ApiExceptions\Contracts\ApiExceptionAbstract;

/**
 * The package's own handler passes Symfony's English message straight through
 * ("The PUT method is not supported for route ..."), which both leaks routing
 * detail and breaks the Persian-first contract. This renders the translated
 * message instead.
 */
class CustomMethodNotAllowedException extends ApiExceptionAbstract
{
    public function setCode(mixed $code = null): self
    {
        $this->code = Response::HTTP_METHOD_NOT_ALLOWED;

        return $this;
    }

    public function setMessage(mixed $message = null): self
    {
        $this->message = trans('errors.method_not_allowed');

        return $this;
    }
}
