<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'email' => 'admin@chambealo.pe',
                'full_name' => 'Administrador Chambealo',
                'password_hash' => Hash::make('Admin12345!'),
                'role' => 'admin',
                'status' => 'active',
                'tenant_id' => 1,
                'registered_at' => now(),
            ],
            [
                'email' => 'vendor@chambealo.pe',
                'full_name' => 'Vendedor Ferretería Central',
                'password_hash' => Hash::make('Vendor12345!'),
                'role' => 'vendor',
                'status' => 'active',
                'tenant_id' => 1,
                'registered_at' => now(),
            ],
            [
                'email' => 'cliente@chambealo.pe',
                'full_name' => 'Cliente QA Chambealo',
                'password_hash' => Hash::make('Cliente12345!'),
                'role' => 'user',
                'status' => 'active',
                'tenant_id' => 1,
                'registered_at' => now(),
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                $userData,
            );
        }
    }
}
