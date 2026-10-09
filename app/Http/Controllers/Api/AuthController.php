<?php
// app/Http/Controllers/Api/AuthController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
            ]);

            $user = User::create([
                'full_name' => $request->name,
                'email' => $request->email,
                'password_hash' => Hash::make($request->password),
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

        } catch (ValidationException $e) {
            // Laravel lo convierte en 422 con los errores por campo
            throw $e;
        } catch (\Exception $e) {
            Log::error('Registration failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Registration failed',
                'message' => 'No se pudo completar el registro'
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

            $token = JWTAuth::fromUser($user);

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

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Login failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Login failed',
                'message' => 'No se pudo iniciar sesión'
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return response()->json(['message' => 'Logout exitoso'], 200);
        } catch (JWTException $e) {
            // Token ausente, inválido o vencido
            return response()->json(['error' => 'Token inválido o vencido'], 401);
        } catch (\Exception $e) {
            Log::error('Logout failed', ['error' => $e->getMessage()]);
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
            // OJO: hoy esto solo invalida el token actual (igual que logout).
            // Para cerrar todos los dispositivos hace falta guardar, por ejemplo,
            // la fecha del último cierre global y rechazar tokens emitidos antes.
            JWTAuth::invalidate(JWTAuth::getToken());
            return response()->json(['message' => 'Logout exitoso'], 200);
        } catch (JWTException $e) {
            return response()->json(['error' => 'Token inválido o vencido'], 401);
        } catch (\Exception $e) {
            Log::error('Logout all failed', ['error' => $e->getMessage()]);
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
            Log::error('Profile failed', ['error' => $e->getMessage()]);
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
                ],
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Update profile failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Error al actualizar perfil'
            ], 500);
        }
    }

    public function refresh(Request $request)
    {
        try {
            $token = JWTAuth::refresh(JWTAuth::getToken());
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