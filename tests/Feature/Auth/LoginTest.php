<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_sign_in_with_a_username(): void
    {
        $user = $this->activeUser(['username' => 'demo']);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'demo',
            'password' => 'password',
        ]);

        $this->assertSuccessEnvelope($response);
        $response->assertJsonStructure([
            'data' => ['token_type', 'access_token', 'expires_in', 'user' => ['id', 'username', 'email']],
        ]);
        $response->assertJsonPath('data.token_type', 'Bearer');
        $response->assertJsonPath('data.user.id', $user->id);
    }

    public function test_a_user_can_sign_in_with_an_email(): void
    {
        $this->activeUser(['username' => 'demo', 'email' => 'demo@lifeweb.local']);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'demo@lifeweb.local',
            'password' => 'password',
        ]);

        $this->assertSuccessEnvelope($response);
    }

    public function test_a_successful_sign_in_records_the_last_login(): void
    {
        $user = $this->activeUser();

        $this->assertNull($user->last_login_at);

        $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertOk();

        $this->assertNotNull($user->fresh()?->last_login_at);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $user = $this->activeUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ]);

        $this->assertFailureEnvelope($response, 401);
    }

    /**
     * An unknown account and a wrong password must be indistinguishable, or the
     * endpoint becomes an account-enumeration oracle.
     */
    public function test_an_unknown_user_is_rejected_with_the_same_message_as_a_wrong_password(): void
    {
        $user = $this->activeUser();

        $unknown = $this->postJson('/api/v1/auth/login', [
            'username' => 'no-such-user',
            'password' => 'password',
        ]);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ]);

        $this->assertFailureEnvelope($unknown, 401);
        $this->assertFailureEnvelope($wrongPassword, 401);
        $this->assertSame(
            $unknown->json('message'),
            $wrongPassword->json('message'),
        );
    }

    public function test_an_inactive_user_cannot_sign_in(): void
    {
        $user = User::factory()->inactive()->create(['password' => Hash::make('password')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertFailureEnvelope($response, 401);
    }

    public function test_missing_credentials_fail_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $this->assertFailureEnvelope($response, 422);
        $response->assertJsonStructure(['error' => ['username', 'password']]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeUser(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['password' => Hash::make('password')]);
    }
}
