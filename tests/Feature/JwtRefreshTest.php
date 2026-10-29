<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_invalidates_old_token_and_returns_new_one(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        $oldToken = JWTAuth::fromUser($user);

        $this->withToken($oldToken)
            ->getJson('/api/profile')
            ->assertOk();

        $response = $this->withToken($oldToken)
            ->postJson('/api/refresh')
            ->assertOk()
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
            ]);

        $newToken = $response->json('access_token');

        $this->assertNotEquals($oldToken, $newToken);

        $this->withToken($oldToken)
            ->getJson('/api/profile')
            ->assertUnauthorized();

        $this->withToken($newToken)
            ->getJson('/api/profile')
            ->assertOk();
    }
}
