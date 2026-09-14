<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The failure envelope is a contract every consumer depends on. These tests also
 * guard against a subtler regression: mehrand/api-exceptions renders its messages
 * through trans('errors.*'), which resolves against lang/<locale>/errors.php in the
 * application. If those files go missing, the API silently returns the raw
 * translation key ("errors.validation") as the user-facing message.
 */
class ErrorEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_route_returns_a_translated_404(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $this->assertFailureEnvelope($response, 404);
        $this->assertNotTranslationKey($response->json('message'));
    }

    public function test_a_validation_failure_returns_a_translated_422_with_field_errors(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $this->assertFailureEnvelope($response, 422);
        $this->assertNotTranslationKey($response->json('message'));
        $response->assertJsonStructure(['error' => ['username', 'password']]);
    }

    public function test_an_unauthenticated_request_returns_a_translated_401(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $this->assertFailureEnvelope($response, 401);
        $this->assertNotTranslationKey($response->json('message'));
    }

    public function test_a_disallowed_method_returns_a_translated_405(): void
    {
        $response = $this->putJson('/api/v1/auth/login');

        $this->assertFailureEnvelope($response, 405);
        $this->assertNotTranslationKey($response->json('message'));
    }

    private function assertNotTranslationKey(mixed $message): void
    {
        $this->assertIsString($message);
        $this->assertStringNotContainsString(
            'errors.',
            $message,
            'The response leaked a raw translation key instead of a message.',
        );
        $this->assertStringNotContainsString('messages.', $message);
    }
}
