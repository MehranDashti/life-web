<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Idempotent — safe to re-run on every deploy. Creates the demo account the
 * README and the load-test scripts authenticate with.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['username' => 'demo'],
            [
                'email' => 'demo@lifeweb.local',
                'name' => 'Demo User',
                'password' => Hash::make('password'),
                'status' => UserStatus::Active,
            ],
        );
    }
}
