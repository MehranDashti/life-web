<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;
use RuntimeException;
use Illuminate\Http\Response;

/**
 * Raised when Elasticsearch is unreachable or returns a server-side failure.
 *
 * Search backs a *reporting* feature, not the core CRUD surface, so an outage
 * must degrade to a clean 503 instead of leaking a driver stack trace as a 500.
 * The underlying exception is preserved as `$previous` for the logs.
 */
class SearchUnavailableException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            trans('messages.search_unavailable'),
            Response::HTTP_SERVICE_UNAVAILABLE,
            $previous,
        );
    }
}
