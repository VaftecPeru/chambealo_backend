<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $email): User
    {
        return User::factory()->create([
            'full_name' => 'Usuario Prueba',
            'email' => $email,
            'password_hash' => Hash::make('password123'),
            'role' => 'user',
            'status' => 'active',
        ]);
    }

    private function headersFor(User $user): array
    {
        $token = JWTAuth::fromUser($user);

        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    public function test_user_can_update_profile(): void
    {
        $user = $this->createUser('perfil@example.com');

        $this->withHeaders($this->headersFor($user))
            ->putJson('/api/profile', [
                'full_name' => 'Nombre Actualizado',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Nombre Actualizado');

        $this->assertDatabaseHas('users', [
            'user_id' => $user->user_id,
            'full_name' => 'Nombre Actualizado',
        ]);
    }

    public function test_invalid_email_returns_422(): void
    {
        $user = $this->createUser('invalido@example.com');

        $this->withHeaders($this->headersFor($user))
            ->putJson('/api/profile', [
                'email' => 'correo-invalido',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_duplicate_email_returns_422(): void
    {
        $user = $this->createUser('primero@example.com');
        $this->createUser('segundo@example.com');

        $this->withHeaders($this->headersFor($user))
            ->putJson('/api/profile', [
                'email' => 'segundo@example.com',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_profile_requires_authentication(): void
    {
        $this->putJson('/api/profile', [
            'full_name' => 'Sin Autenticacion',
        ])->assertUnauthorized();
    }
}
