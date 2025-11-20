<?php
// app/Http/Controllers/AuthController.php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        try {
            // Validación básica
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
            ]);

            // 🔥 CORREGIDO: Usar password_hash directamente
            $user = User::create([
                'full_name' => $request->name,
                'email' => $request->email,
                'password_hash' => Hash::make($request->password), // ← Hash directo
                'role' => 'user',
                'status' => 'active',
                'registered_at' => now(),
            ]);

            return response()->json([
                'message' => 'Usuario registrado exitosamente',
                'user' => [
                    'user_id' => $user->user_id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'role' => $user->role,
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Registration failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            $user = User::where('email', $request->email)->first();

            // Verificar contraseña con password_hash
            if (!$user || !Hash::check($request->password, $user->password_hash)) {
                return response()->json([
                    'error' => 'Unauthorized',
                    'message' => 'Credenciales incorrectas'
                ], 401);
            }

            if (!$user->isActive()) {
                return response()->json([
                    'error' => 'Account suspended',
                    'message' => 'Tu cuenta está desactivada'
                ], 403);
            }

            return response()->json([
                'message' => 'Login exitoso',
                'user' => [
                    'user_id' => $user->user_id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'role' => $user->role,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Login failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
