<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_validation_returns_422_instead_of_500(): void
    {
        $this->postJson('/api/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_login_validation_returns_422_instead_of_500(): void
    {
        $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_update_profile_invalid_email_returns_422(): void
    {
        // Authenticated profile routes require a tenant context. Keep the
        // fixture authorized so this test reaches the validation layer.
        $user = \App\Models\User::factory()->create();
        $user->forceFill(['tenant_id' => 1])->save();
        $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/profile', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
