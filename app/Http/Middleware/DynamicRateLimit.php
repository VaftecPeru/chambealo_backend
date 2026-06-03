<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DynamicRateLimit
{
    public function handle(Request $request, Closure $next, $action = null)
    {
        $action = $action ?? $request->route()->getName() ?? 'default';
        $userId = auth()->id() ?? 'guest';
        $ip = $request->ip();
        
        // Configuración de límites
        $limits = config("rate_limits.{$action}", [
            'per_minute' => 60,
            'per_hour' => 1000,
        ]);
        
        $key = "rate_limit:{$action}:{$userId}:{$ip}:" . date('YmdHi');
        $current = (int)Cache::get($key, 0);
        
        if ($current >= $limits['per_minute']) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests',
                'code' => 'RATE_LIMIT_EXCEEDED'
            ], 429);
        }
        
        Cache::increment($key);
        Cache::expire($key, 60);
        
        return $next($request);
    }
}