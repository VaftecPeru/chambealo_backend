<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_brute_force_protection()
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password_hash' => Hash::make('password'),
        ]);

        // Realizar seis intentos con una contraseña incorrecta.
        for ($i = 0; $i < 6; $i++) {
            $response = $this->withServerVariables([
                'REMOTE_ADDR' => '192.0.2.10',
            ])->postJson('/api/login', [
                'email' => 'test@example.com',
                'password' => 'wrong_password',
            ]);
        }

        $response->assertStatus(429);
    }


    public function test_token_refresh_security()
    {
        $user = User::factory()->create();
        auth()->login($user);

        // Intentar renovar el token varias veces.
        $refreshToken = 'fake_refresh_token';

        for ($i = 0; $i < 11; $i++) {
            $response = $this->postJson('/api/refresh', [
                'refresh_token' => $refreshToken,
            ]);
        }

        $response->assertStatus(429);
    }

    public function test_inactive_user_cannot_login()
    {
        $user = User::factory()->create([
            'status' => 'suspended',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(403);
    }
}
