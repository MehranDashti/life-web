<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use App\Exceptions\SearchUnavailableException;
use Mehrand\ApiExceptions\Handlers\ApiException;
use Mehrand\ApiResponse\Responses\FailureResponse;
use Elastic\Transport\Exception\TransportException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Elastic\Elasticsearch\Exception\ElasticsearchException as ElasticsearchExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: function (): void {
            Route::middleware(['api', SubstituteBindings::class])
                ->prefix('api/v1')
                ->as('v1.')
                ->group(base_path('routes/api/v1.php'));
        },
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            // Laravel redirects unauthenticated requests to the named 'login' route,
            // which does not exist in an API-only app. Normalise that into a 401
            // instead of letting a RouteNotFoundException surface as a 500.
            if ($e instanceof RouteNotFoundException || str_contains($e->getMessage(), '[login]')) {
                $e = new RuntimeException(trans('messages.user_is_unauthenticated'), Response::HTTP_UNAUTHORIZED);
            }

            if ($e instanceof UnauthorizedHttpException) {
                $e = new RuntimeException(trans('messages.user_is_unauthenticated'), Response::HTTP_UNAUTHORIZED);
            }

            // Symfony HttpExceptions carry their status in getStatusCode(); getCode()
            // is 0, which is all the exception map reads. Left alone, a rate-limited
            // caller gets a 500 instead of a 429 — and loses Retry-After with it, so
            // a well-behaved client has nothing to back off on. Handled here rather
            // than through config/exceptions.php because the renderer there builds its
            // own response and would drop the headers.
            if ($e instanceof ThrottleRequestsException) {
                return (new FailureResponse)
                    ->setCode(Response::HTTP_TOO_MANY_REQUESTS)
                    ->setMessage(trans('messages.too_many_requests'))
                    ->render()
                    ->withHeaders($e->getHeaders());
            }

            // Elasticsearch failures can only be matched by interface, which
            // config/exceptions.php (an exact class-name map) cannot express.
            // Log the real cause, then hand the caller a clean 503.
            if ($e instanceof ElasticsearchExceptionInterface || $e instanceof TransportException) {
                Log::error('elasticsearch request failed', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'path' => $request->path(),
                ]);

                $e = new SearchUnavailableException($e);
            }

            return ApiException::handle($e);
        });
    })
    ->create();
