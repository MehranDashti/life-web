<?php

declare(strict_types=1);

namespace Database\Seeders;

use Laravel\Passport\Passport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\ClientRepository;

/**
 * Creates the OAuth personal access client that `$user->createToken()` requires.
 *
 * Without it, every sign-in fails with "Personal access client not found". It is
 * a deployment prerequisite, not developer convenience, so it lives in a seeder
 * that the entrypoint runs on every boot rather than in a one-off manual command.
 *
 * Idempotent: an existing, unrevoked personal-access client for the `users`
 * provider is left alone.
 */
class PassportClientSeeder extends Seeder
{
    public function run(): void
    {
        $provider = (string) config('auth.guards.api.provider', 'users');

        $existing = Passport::client()
            ->newQuery()
            ->where('revoked', false)
            ->get()
            ->first(fn ($client): bool => $client->hasGrantType('personal_access'));

        if ($existing !== null) {
            $this->report("Personal access client already present ({$existing->getKey()}).");

            return;
        }

        $client = app(ClientRepository::class)
            ->createPersonalAccessGrantClient(config('app.name').' Personal Access Client', $provider);

        $this->report("Created personal access client {$client->getKey()}.");
    }

    /**
     * Logged rather than printed: this seeder runs from the container entrypoint
     * and from tests, where there is no console to write to, and the log is where
     * a fresh deploy is diagnosed from anyway.
     */
    private function report(string $message): void
    {
        Log::info($message);
    }
}
