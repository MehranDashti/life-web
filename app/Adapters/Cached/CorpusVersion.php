<?php

declare(strict_types=1);

namespace App\Adapters\Cached;

use Throwable;
use Illuminate\Contracts\Cache\Repository;

/**
 * A monotonic counter that forms part of every search cache key.
 *
 * Invalidation by version rather than by tag flush: a write to the index
 * increments one integer, which orphans every previously cached result at once
 * and lets the TTL reclaim them. Tag flushing would mean tracking and deleting
 * every key on every import, which is linear in the number of cached queries
 * rather than constant.
 */
final readonly class CorpusVersion
{
    private const string KEY = 'search:corpus:version';

    /**
     * Returned when the store cannot be read. A constant is correct here: if the
     * version cannot be established, every key collapses onto the same namespace
     * and the TTL alone bounds staleness — which is the same guarantee a
     * cache-less system offers, not a worse one.
     */
    private const int FALLBACK = 0;

    public function __construct(private Repository $cache) {}

    /**
     * The current corpus version, or the fallback when the store is unreachable.
     */
    public function current(): int
    {
        try {
            return (int) $this->cache->get(self::KEY, 1);
        } catch (Throwable) {
            return self::FALLBACK;
        }
    }

    /**
     * Invalidate every cached search result.
     *
     * Uses the store's atomic increment where available so two importers running
     * concurrently cannot lose a bump to a read-modify-write race.
     */
    public function bump(): void
    {
        try {
            if ($this->cache->get(self::KEY) === null) {
                $this->cache->forever(self::KEY, 2);

                return;
            }

            $this->cache->increment(self::KEY);
        } catch (Throwable) {
            // A cache that cannot record the bump must not fail the import. The
            // TTL still bounds how long a stale result can survive.
        }
    }
}
