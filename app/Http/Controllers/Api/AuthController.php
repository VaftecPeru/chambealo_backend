<?php
// app/Http/Controllers/AuthController.php

namespace App\Http\Controllers\Api; // <-- Agrega el \Api al final

use App\Http\Controllers\Controller;
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

    public function logout(Request $request)
    {
        try {
            \Tymon\JWTAuth\Facades\JWTAuth::invalidate(\Tymon\JWTAuth\Facades\JWTAuth::getToken());
            return response()->json(['message' => 'Logout exitoso'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Logout failed'], 500);
        }
    }

    public function logoutAllDevices(Request $request)
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return response()->json(['error' => 'No autenticado'], 401);
            }
            \Tymon\JWTAuth\Facades\JWTAuth::invalidate(\Tymon\JWTAuth\Facades\JWTAuth::getToken());
            return response()->json(['message' => 'Logout en todos los dispositivos exitoso'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al cerrar sesión'], 500);
        }
    }

    public function profile(Request $request)
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return response()->json(['error' => 'No autenticado'], 401);
            }
            return response()->json([
                'user' => [
                    'user_id' => $user->user_id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener perfil'], 500);
        }
    }

    public function updateProfile(Request $request)
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return response()->json(['error' => 'No autenticado'], 401);
            }
            
            $request->validate([
                'full_name' => 'sometimes|string|max:255',
                'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->user_id . ',user_id',
            ]);

            $user->update($request->only(['full_name', 'email']));

            return response()->json([
                'message' => 'Perfil actualizado exitosamente',
                'user' => [
                    'user_id' => $user->user_id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'role' => $user->role,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al actualizar perfil'], 500);
        }
    }

    public function refresh(Request $request)
    {
        try {
            $token = \Tymon\JWTAuth\Facades\JWTAuth::refresh(\Tymon\JWTAuth\Facades\JWTAuth::getToken());
            return response()->json([
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Token refresh failed'], 401);
        }
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

            // VAFTEC: Generar JWT token en backend, no en frontend
            $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);

            return response()->json([
                'message' => 'Login exitoso',
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
                'user' => [
                    'user_id' => $user->user_id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'role' => $user->role,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Login failed'
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
