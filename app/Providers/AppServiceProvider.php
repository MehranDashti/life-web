<?php

declare(strict_types=1);

namespace App\Providers;

use RuntimeException;
use Illuminate\Http\Request;
use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Adapters\Cached\CorpusVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use App\Adapters\Fake\FakeSearchAdapter;
use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use App\Adapters\Cached\CachedSearchAdapter;
use App\Adapters\Contracts\SearchAdapterInterface;
// The facade above already owns the short name, so the concrete limiter the
// container binds is aliased rather than imported bare.
use App\Adapters\Elasticsearch\ElasticsearchAdapter;
use Illuminate\Cache\RateLimiter as RateLimiterService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerSearchAdapter();
        $this->registerRateLimiterStore();
    }

    public function boot(): void
    {
        $this->registerRateLimiters();

        // Fail loudly outside production on lazy-loaded relations, mass-assignment
        // mistakes, and access to attributes the query did not select. These are the
        // three defects that survive review and surface later as N+1s or missing data.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Any query slower than 500ms is worth seeing in the log.
        DB::whenQueryingForLongerThan(500, function ($connection, $event): void {
            logger()->warning('slow query', [
                'sql' => $event->sql,
                'time_ms' => $event->time,
                'connection' => $connection->getName(),
            ]);
        });
    }

    /**
     * Laravel 13 ships no default `api` limiter, but bootstrap/app.php applies
     * `throttleApi('api')` to the whole surface — so it must be defined here or
     * every authenticated request 500s.
     *
     * Authenticated callers are limited per user id so one noisy client cannot
     * consume another's budget; anonymous callers fall back to IP.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute(
            (int) config('app.api_rate_limit', 120),
        )->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }

    /**
     * One switch, one place. Adding an engine means adding an adapter class and a
     * match arm — never a conditional inside a service.
     */
    private function registerSearchAdapter(): void
    {
        $this->app->singleton(Client::class, function (): Client {
            /** @var array<string, mixed> $config */
            $config = config('search.elasticsearch');

            $builder = ClientBuilder::create()
                ->setHosts($config['hosts'])
                ->setRetries((int) $config['retries']);

            if (is_string($config['api_key']) && $config['api_key'] !== '') {
                $builder->setApiKey($config['api_key']);
            } elseif (is_string($config['username']) && $config['username'] !== '') {
                $builder->setBasicAuthentication($config['username'], (string) $config['password']);
            }

            if (is_string($config['ca_bundle']) && $config['ca_bundle'] !== '') {
                $builder->setCABundle($config['ca_bundle']);
            }

            return $builder->build();
        });

        $this->app->singleton(SearchAdapterInterface::class, function (): SearchAdapterInterface {
            $driver = (string) config('search.driver');

            $adapter = match ($driver) {
                'elasticsearch' => new ElasticsearchAdapter(
                    $this->app->make(Client::class),
                    (array) config('search.elasticsearch'),
                ),
                'fake' => new FakeSearchAdapter,
                default => throw new RuntimeException("Unsupported search driver [{$driver}]."),
            };

            return config('search.cache.enabled')
                ? $this->wrapInCache($adapter)
                : $adapter;
        });
    }

    /**
     * Wrap a search adapter so identical queries issued in the same scheduler
     * tick cost one aggregation instead of one each.
     *
     * A decorator keeps the engine class free of caching concerns and leaves the
     * contract unchanged for every caller.
     */
    private function wrapInCache(SearchAdapterInterface $adapter): SearchAdapterInterface
    {
        $store = Cache::store(config('search.cache.store'));

        return new CachedSearchAdapter(
            inner: $adapter,
            cache: $store,
            version: new CorpusVersion($store),
            ttl: (int) config('search.cache.ttl'),
            countTtl: (int) config('search.cache.count_ttl'),
        );
    }

    /**
     * Back the rate limiter with a store shared by every application instance.
     *
     * The limiter otherwise uses the default cache store, so with the file store
     * and N app containers each keeps its own bucket and the effective limit is
     * N times the configured one — which contradicts the horizontal-scaling
     * design the rest of the system is built for.
     */
    private function registerRateLimiterStore(): void
    {
        $this->app->singleton(
            RateLimiterService::class,
            static fn (): RateLimiterService => new RateLimiterService(Cache::store(config('cache.limiter'))),
        );
    }
}
