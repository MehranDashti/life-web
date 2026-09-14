<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Illuminate\Contracts\Cache\Store;

/**
 * A cache store where every operation fails, for proving that a cache outage
 * slows the system down rather than breaking it.
 */
final class ThrowingCacheStore implements Store
{
    public function get($key): mixed
    {
        throw new RuntimeException('cache unavailable');
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        throw new RuntimeException('cache unavailable');
    }

    public function put($key, $value, $seconds): bool
    {
        throw new RuntimeException('cache unavailable');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values, $seconds): bool
    {
        throw new RuntimeException('cache unavailable');
    }

    public function increment($key, $value = 1): bool
    {
        throw new RuntimeException('cache unavailable');
    }

    public function decrement($key, $value = 1): bool
    {
        throw new RuntimeException('cache unavailable');
    }

    public function forever($key, $value): bool
    {
        throw new RuntimeException('cache unavailable');
    }

    public function touch($key, $seconds): bool
    {
        throw new RuntimeException('cache unavailable');
    }

    public function forget($key): bool
    {
        throw new RuntimeException('cache unavailable');
    }

    public function flush(): bool
    {
        throw new RuntimeException('cache unavailable');
    }

    public function getPrefix(): string
    {
        return '';
    }
}
