<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Hash;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_brute_force_protection()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password')
        ]);

        // Intentar login múltiples veces
        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => 'test@example.com',
                'password' => 'wrong_password'
            ]);
        }

        $response->assertStatus(429); // Too Many Requests
    }

    public function test_token_refresh_security()
    {
        $user = User::factory()->create();
        $token = auth()->login($user);


        // Intentar usar refresh token múltiples veces
        $refreshToken = 'fake_refresh_token';

        for ($i = 0; $i < 11; $i++) {
            $response = $this->postJson('/api/refresh', [
                'refresh_token' => $refreshToken
            ]);
        }

        $response->assertStatus(429);
    }

    public function test_inactive_user_cannot_login()
    {
        $user = User::factory()->create([
            'status' => 'suspended'
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password'
        ]);

        $response->assertStatus(403);
    }
}
