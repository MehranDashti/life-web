<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User\User;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_current_user_can_read_their_own_profile(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/auth/me');

        $this->assertSuccessEnvelope($response);
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.username', $user->username);
    }

    public function test_the_password_is_never_exposed(): void
    {
        Passport::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk();
        $this->assertArrayNotHasKey('password', (array) $response->json('data'));
    }

    public function test_an_anonymous_request_is_rejected_with_the_standard_envelope(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $this->assertFailureEnvelope($response, 401);
    }

    public function test_a_user_can_sign_out(): void
    {
        Passport::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/auth/logout');

        $this->assertSuccessEnvelope($response);
    }

    /**
     * Signing out must actually revoke the token. `currentAccessToken()` is typed as
     * ScopeAuthorizable, so it is easy to narrow it to the wrong concrete class and
     * end up with a logout that reports success and revokes nothing.
     */
    public function test_signing_out_revokes_the_token_used_for_the_request(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $token = $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'password',
        ])->json('data.access_token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->forgetAuthState();

        $this->assertFailureEnvelope(
            $this->withToken($token)->getJson('/api/v1/auth/me'),
            401,
        );
    }

    /**
     * Signing out on one device must not sign the user out everywhere.
     */
    public function test_signing_out_leaves_other_tokens_working(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $first = $this->login($user->username);
        $second = $this->login($user->username);

        $this->withToken($first)->postJson('/api/v1/auth/logout')->assertOk();
        $this->forgetAuthState();

        $this->withToken($second)->getJson('/api/v1/auth/me')->assertOk();
    }

    private function login(string $username): string
    {
        $this->forgetAuthState();

        return (string) $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => 'password',
        ])->json('data.access_token');
    }
}
