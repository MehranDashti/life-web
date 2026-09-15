<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Contention is simulated with a second database connection rather than a mock,
 * because a MySQL named lock is held per *session*: two calls on one connection
 * both succeed, so a single-connection test would assert the opposite of the
 * behaviour that matters. `--timeout=0` keeps the contended case instant.
 */
class MigrateWithLockCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string CONNECTION = 'lock_contender';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::CONNECTION => config('database.connections.mysql')]);
    }

    protected function tearDown(): void
    {
        $this->releaseContendedLock();

        parent::tearDown();
    }

    public function test_it_migrates_when_the_lock_is_available(): void
    {
        $this->artisan('migrate:locked')->assertExitCode(0);
    }

    public function test_it_releases_the_lock_after_migrating(): void
    {
        $this->artisan('migrate:locked')->assertExitCode(0);

        $this->assertFalse($this->lockIsHeld(), 'The migration lock was still held after the command finished.');
    }

    public function test_it_refuses_to_migrate_while_another_instance_holds_the_lock(): void
    {
        $this->holdContendedLock();

        $this->artisan('migrate:locked', ['--timeout' => 0])
            ->expectsOutputToContain('waiting for the migration lock')
            ->assertExitCode(1);
    }

    public function test_it_releases_the_lock_when_migrating_fails(): void
    {
        // The stand-in has to accept --force, because that is what the command
        // under test passes; a signature-less closure fails on the option rather
        // than on the throw, and would pass this test for the wrong reason.
        Artisan::command('migrate {--force}', function (): void {
            throw new RuntimeException('migration exploded');
        });

        try {
            $this->artisan('migrate:locked')->run();
        } catch (RuntimeException) {
            // The failure is expected; what this test asserts is what happened to
            // the lock on the way out, not that the migration failed.
        }

        $this->assertFalse($this->lockIsHeld(), 'A failed migration left the lock held, wedging every later deploy.');
    }

    /**
     * Take the lock on a separate session, standing in for another instance.
     */
    private function holdContendedLock(): void
    {
        DB::connection(self::CONNECTION)->selectOne(
            'SELECT GET_LOCK(?, 0) AS acquired',
            [$this->lockName()],
        );
    }

    /**
     * Free the contended lock so a failing test cannot leak it into the next one.
     */
    private function releaseContendedLock(): void
    {
        DB::connection(self::CONNECTION)->selectOne(
            'SELECT RELEASE_LOCK(?) AS released',
            [$this->lockName()],
        );

        DB::connection(self::CONNECTION)->disconnect();
    }

    /**
     * Whether any session currently holds the migration lock.
     */
    private function lockIsHeld(): bool
    {
        $result = DB::connection(self::CONNECTION)->selectOne(
            'SELECT IS_USED_LOCK(?) AS holder',
            [$this->lockName()],
        );

        return is_object($result) && $result->holder !== null;
    }

    /**
     * The configured lock name, read rather than hardcoded so a rename does not
     * leave this suite asserting against a lock nothing uses.
     */
    private function lockName(): string
    {
        return (string) config('database.migrations.lock.name');
    }
}
