<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated'
            ], 401);
        }

        if (!in_array($user->role, $roles)) {
            return response()->json([
                'error' => 'Insufficient permissions'
            ], 403);
        }

        return $next($request);
    }
}
