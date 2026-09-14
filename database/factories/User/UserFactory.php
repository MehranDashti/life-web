<?php

declare(strict_types=1);

namespace Database\Factories\User;

use App\Enums\UserStatus;
use App\Models\User\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'password' => Hash::make('password'),
            'status' => UserStatus::Active,

            // Declared explicitly so a freshly created model carries every attribute a
            // persisted row has. Model::shouldBeStrict() otherwise throws the first time
            // a resource reads a column the factory never set.
            'last_login_ip' => null,
            'last_login_at' => null,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['status' => UserStatus::Inactive]);
    }
}
