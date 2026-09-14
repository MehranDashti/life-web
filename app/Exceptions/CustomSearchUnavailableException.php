<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Response;
use Mehrand\ApiExceptions\Contracts\ApiExceptionAbstract;

/**
 * Renders SearchUnavailableException into the standard failure envelope.
 */
class CustomSearchUnavailableException extends ApiExceptionAbstract
{
    public function setCode(mixed $code = null): self
    {
        $this->code = Response::HTTP_SERVICE_UNAVAILABLE;

        return $this;
    }

    public function setMessage(mixed $message = null): self
    {
        $this->message = trans('messages.search_unavailable');

        return $this;
    }
}
