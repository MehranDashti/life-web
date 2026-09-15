<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Runs migrations behind a MySQL named lock, so that starting any number of
 * instances concurrently still migrates exactly once.
 *
 * A named lock is held by the database *session*, which is what makes it the
 * right primitive here: an instance killed mid-migration drops the lock when its
 * connection dies, so there is no stale entry to reap and a crashed deploy cannot
 * wedge the next one. A lock row in a table would reintroduce exactly that
 * problem, and a Redis lock would put schema safety behind a cache this codebase
 * deliberately allows to fail open.
 */
class MigrateWithLockCommand extends Command
{
    protected $signature = 'migrate:locked
                            {--timeout= : Seconds to wait for the lock, overriding the configured value}';

    protected $description = 'Run database migrations behind a lock so concurrent instances migrate exactly once';

    /**
     * Acquire the lock, migrate, and release it whether or not migrating worked.
     *
     * The release sits in a `finally` rather than after the call so that a
     * throwing migration still frees the lock on its way out; the exception is
     * left to propagate, because a failed migration must not report success.
     */
    public function handle(): int
    {
        $name = $this->lockName();
        $timeout = $this->lockTimeout();

        if (! $this->acquire($name, $timeout)) {
            $this->components->error(
                "Timed out after {$timeout}s waiting for the migration lock '{$name}'. Another instance is migrating.",
            );

            return self::FAILURE;
        }

        try {
            return $this->migrate();
        } finally {
            $this->release($name);
        }
    }

    /**
     * The lock name, shared by every instance that migrates this schema.
     */
    private function lockName(): string
    {
        return (string) config('database.migrations.lock.name', 'lifeweb:migrate');
    }

    /**
     * How long to wait for the lock before giving up.
     *
     * This has to exceed the longest expected migration run, or a waiting
     * instance gives up while the migrator is still working.
     */
    private function lockTimeout(): int
    {
        $override = $this->option('timeout');

        return is_numeric($override)
            ? (int) $override
            : (int) config('database.migrations.lock.timeout', 120);
    }

    /**
     * Block until the lock is held or the timeout elapses.
     *
     * `GET_LOCK` returns 1 when acquired, 0 on timeout, and NULL on error; only
     * the first counts as success, so an error is treated as "did not acquire"
     * rather than being allowed to fall through into a migration run.
     */
    private function acquire(string $name, int $timeout): bool
    {
        $result = DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeout]);

        return is_object($result) && (int) ($result->acquired ?? 0) === 1;
    }

    /**
     * Release the lock so the next waiting instance can proceed.
     */
    private function release(string $name): void
    {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
    }

    /**
     * Run the migrations themselves, forwarding the migrator's exit code.
     */
    private function migrate(): int
    {
        return $this->call('migrate', ['--force' => true]);
    }
}
