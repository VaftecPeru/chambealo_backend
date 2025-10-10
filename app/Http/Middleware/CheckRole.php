<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (!$request->user()) {
            return response()->json([
                'message' => 'No autenticado'
            ], 401);
        }

        $userRole = $request->user()->role->nombre;

        // El superadministrador tiene acceso a todo
        if ($userRole === 'superadministrador') {
            return $next($request);
        }

        // Verificar si el usuario tiene alguno de los roles permitidos
        if (in_array($userRole, $roles)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'No tienes permisos para acceder a este recurso'
        ], 403);
    }
}