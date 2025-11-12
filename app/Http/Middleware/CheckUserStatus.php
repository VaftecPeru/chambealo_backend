<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class CheckUserStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            // Obtener el usuario autenticado
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                return response()->json([
                    'error' => 'Usuario no encontrado',
                    'message' => 'El token es válido pero el usuario no existe'
                ], Response::HTTP_NOT_FOUND);
            }

            // Verificar el estado del usuario
            switch ($user->status) {
                case 'inactive':
                    return response()->json([
                        'error' => 'Cuenta inactiva',
                        'message' => 'Tu cuenta está inactiva. Contacta al administrador.'
                    ], Response::HTTP_FORBIDDEN);

                case 'suspended':
                    return response()->json([
                        'error' => 'Cuenta suspendida',
                        'message' => 'Tu cuenta ha sido suspendida. Contacta al administrador para más información.'
                    ], Response::HTTP_FORBIDDEN);

                case 'pending_verification':
                    return response()->json([
                        'error' => 'Verificación pendiente',
                        'message' => 'Tu cuenta está pendiente de verificación. Revisa tu email.'
                    ], Response::HTTP_FORBIDDEN);

                case 'active':
                    // Usuario activo, continuar con la request
                    return $next($request);

                default:
                    return response()->json([
                        'error' => 'Estado de cuenta desconocido',
                        'message' => 'El estado de tu cuenta no es válido. Contacta al administrador.'
                    ], Response::HTTP_FORBIDDEN);
            }

        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json([
                'error' => 'Token expirado',
                'message' => 'Tu sesión ha expirado. Por favor, inicia sesión nuevamente.'
            ], Response::HTTP_UNAUTHORIZED);

        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json([
                'error' => 'Token inválido',
                'message' => 'El token de autenticación no es válido.'
            ], Response::HTTP_UNAUTHORIZED);

        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            return response()->json([
                'error' => 'Token ausente',
                'message' => 'Token de autenticación no proporcionado.'
            ], Response::HTTP_UNAUTHORIZED);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error de autenticación',
                'message' => 'Ocurrió un error al verificar tu autenticación.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
