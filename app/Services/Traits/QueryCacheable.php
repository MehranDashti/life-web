<?php

declare(strict_types=1);

namespace App\Services\Traits;

use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

/**
 * Caching for read-heavy list endpoints.
 *
 * Each consumer picks one cache tag; reads are cached per distinct query
 * signature under that tag, and any write flushes the whole tag so the next read
 * refills it.
 *
 * Tag-based invalidation needs a tag-capable store (Redis, Memcached, array). On
 * a store that cannot tag — `file` and `database` — caching is skipped rather
 * than attempted: a caching optimisation must never be the reason an endpoint
 * returns an error, and silently serving uncached results is strictly better than
 * failing the request.
 *
 * Can also be switched off wholesale via `cache.query_cache_enabled`, which the
 * test environment does so a stale read can never mask a real assertion.
 */
trait QueryCacheable
{
    private const DEFAULT_QUERY_CACHE_TTL = 300;

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    protected function rememberQueryCache(string $tag, string $key, Closure $callback, int $ttl = self::DEFAULT_QUERY_CACHE_TTL): mixed
    {
        if (! $this->queryCacheAvailable()) {
            return $callback();
        }

        return Cache::tags([$tag])->remember($key, $ttl, $callback);
    }

    /**
     * Invalidate every cached read under a tag. Called on any write to the domain.
     */
    protected function flushQueryCache(string $tag): void
    {
        if (! $this->queryCacheAvailable()) {
            return;
        }

        Cache::tags([$tag])->flush();
    }

    /**
     * Enabled by configuration AND backed by a store that can actually tag.
     */
    protected function queryCacheAvailable(): bool
    {
        return (bool) config('cache.query_cache_enabled')
            && Cache::getStore() instanceof TaggableStore;
    }

    /**
     * Scoped by the authenticated user as well as the path and query string.
     *
     * Every list endpoint in this API is owner-scoped, so a key built only from
     * the request would serve one user's reports to another — the single failure
     * mode this helper must not have.
     */
    protected function queryCacheKey(string $tag): string
    {
        $signature = implode('|', [
            request()->path(),
            request()->getQueryString() ?? '',
            (string) (auth('api')->id() ?? 'guest'),
        ]);

        return $tag.':'.md5($signature);
    }
}
