<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Throwable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Response as HttpResponse;
use App\Adapters\Contracts\SearchAdapterInterface;

/**
 * Liveness/readiness probe, also used as the Docker healthcheck.
 *
 * Each dependency is probed independently so a partial outage is visible rather
 * than collapsing into a single boolean: the API can still serve reads when
 * Elasticsearch is down, and saying so is more useful than reporting "unhealthy".
 */
class HealthCheckController extends Controller
{
    public function __invoke(SearchAdapterInterface $search): JsonResponse
    {
        $checks = [
            'database' => $this->probe(static function (): bool {
                DB::connection()->select('select 1');

                return true;
            }),
            'cache' => $this->probe(static function (): bool {
                Cache::put('health:ping', '1', 5);

                return Cache::get('health:ping') === '1';
            }),
            'search' => $this->probe(static fn (): bool => $search->ping()),
        ];

        // The database is the only hard dependency — without it nothing works.
        $healthy = $checks['database'];

        return $this->successResponse(
            trans('messages.action_successfully_done'),
            [
                'status' => $healthy ? 'ok' : 'degraded',
                'checks' => $checks,
                'version' => (string) config('app.version', 'dev'),
            ],
        )->setStatusCode($healthy ? HttpResponse::HTTP_OK : HttpResponse::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * @param  callable(): bool  $check
     */
    private function probe(callable $check): bool
    {
        try {
            return $check();
        } catch (Throwable) {
            return false;
        }
    }
}
