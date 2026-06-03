<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait RateLimitTrait
{
    /**
     * Verificar rate limit
     */
    protected function checkRateLimit(Request $request, string $action): ?JsonResponse
    {
        $limits = config("rate_limits.{$action}", config('rate_limits.default'));
        $userId = auth()->id() ?? 'guest';
        $ip = $request->ip();
        
        // Verificar por minuto
        $minuteKey = "rate_limit:{$action}:minute:" . date('YmdHi');
        $minuteCount = (int)Cache::get($minuteKey, 0);
        
        if ($minuteCount >= $limits['per_minute']) {
            return $this->rateLimitResponse('MINUTE_LIMIT', 60 - date('s'), $limits);
        }
        
        // Verificar por hora
        $hourKey = "rate_limit:{$action}:hour:" . date('YmdH');
        $hourCount = (int)Cache::get($hourKey, 0);
        
        if ($hourCount >= $limits['per_hour']) {
            return $this->rateLimitResponse('HOUR_LIMIT', 3600 - (date('i') * 60 + date('s')), $limits);
        }
        
        // Verificar por usuario
        if (auth()->check() && isset($limits['per_user_minute'])) {
            $userKey = "rate_limit:{$action}:user:{$userId}:minute:" . date('YmdHi');
            $userCount = (int)Cache::get($userKey, 0);
            
            if ($userCount >= $limits['per_user_minute']) {
                return $this->rateLimitResponse('USER_LIMIT', 60 - date('s'), $limits);
            }
            Cache::increment($userKey);
            Cache::expire($userKey, 60);
        }
        
        // Verificar por IP
        if (isset($limits['per_ip_minute'])) {
            $ipKey = "rate_limit:{$action}:ip:{$ip}:minute:" . date('YmdHi');
            $ipCount = (int)Cache::get($ipKey, 0);
            
            if ($ipCount >= $limits['per_ip_minute']) {
                return $this->rateLimitResponse('IP_LIMIT', 60 - date('s'), $limits);
            }
            Cache::increment($ipKey);
            Cache::expire($ipKey, 60);
        }
        
        // Incrementar contadores globales
        Cache::increment($minuteKey);
        Cache::expire($minuteKey, 60);
        
        Cache::increment($hourKey);
        Cache::expire($hourKey, 3600);
        
        return null;
    }
    
    /**
     * Respuesta de rate limit
     */
    protected function rateLimitResponse(string $type, int $retryAfter, array $limits): JsonResponse
    {
        Log::warning('Rate limit exceeded', [
            'type' => $type,
            'retry_after' => $retryAfter,
            'limits' => $limits
        ]);
        
        return response()->json([
            'success' => false,
            'message' => "Rate limit exceeded. Try again in {$retryAfter} seconds.",
            'code' => 'RATE_LIMIT_EXCEEDED',
            'data' => [
                'limit_type' => $type,
                'retry_after' => $retryAfter,
                'max_requests' => $limits['per_minute'] ?? $limits['per_hour']
            ]
        ], 429);
    }
    
    /**
     * Obtener headers de rate limit
     */
    protected function getRateLimitHeaders(Request $request, string $action): array
    {
        $limits = config("rate_limits.{$action}", config('rate_limits.default'));
        $current = (int)Cache::get("rate_limit:{$action}:minute:" . date('YmdHi'), 0);
        
        return [
            'X-RateLimit-Limit' => $limits['per_minute'],
            'X-RateLimit-Remaining' => max(0, $limits['per_minute'] - $current),
            'X-RateLimit-Reset' => now()->addMinutes(1)->timestamp,
        ];
    }
}