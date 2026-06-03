<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RateLimitService
{
    private array $limits;
    private string $action;
    private string $userId;
    private string $ip;
    
    public function __construct(string $action, ?int $userId = null, ?string $ip = null)
    {
        $this->action = $action;
        $this->userId = $userId ?? 'guest';
        $this->ip = $ip ?? request()->ip();
        $this->limits = config("rate_limits.{$action}", config('rate_limits.default'));
    }
    
    /**
     * Verificar si está dentro de los límites
     */
    public function check(): array
    {
        $checks = [
            $this->checkMinute(),
            $this->checkHour(),
            $this->checkDay(),
        ];
        
        if (auth()->check()) {
            $checks[] = $this->checkUserMinute();
        }
        
        $checks[] = $this->checkIpMinute();
        
        foreach ($checks as $check) {
            if (!$check['allowed']) {
                return $check;
            }
        }
        
        $this->incrementCounters();
        
        return ['allowed' => true];
    }
    
    private function checkMinute(): array
    {
        $key = "rate_limit:{$this->action}:minute:" . date('YmdHi');
        $current = (int)Cache::get($key, 0);
        
        if ($current >= $this->limits['per_minute']) {
            return [
                'allowed' => false,
                'type' => 'GLOBAL_MINUTE',
                'retry_after' => 60 - date('s'),
                'current' => $current,
                'limit' => $this->limits['per_minute']
            ];
        }
        
        return ['allowed' => true];
    }
    
    private function checkHour(): array
    {
        $key = "rate_limit:{$this->action}:hour:" . date('YmdH');
        $current = (int)Cache::get($key, 0);
        
        if ($current >= $this->limits['per_hour']) {
            return [
                'allowed' => false,
                'type' => 'GLOBAL_HOUR',
                'retry_after' => 3600 - (date('i') * 60 + date('s')),
                'current' => $current,
                'limit' => $this->limits['per_hour']
            ];
        }
        
        return ['allowed' => true];
    }
    
    private function checkDay(): array
    {
        if (!isset($this->limits['per_day'])) {
            return ['allowed' => true];
        }
        
        $key = "rate_limit:{$this->action}:day:" . date('Ymd');
        $current = (int)Cache::get($key, 0);
        
        if ($current >= $this->limits['per_day']) {
            $remainingSeconds = 86400 - (date('H') * 3600 + date('i') * 60 + date('s'));
            return [
                'allowed' => false,
                'type' => 'GLOBAL_DAY',
                'retry_after' => $remainingSeconds,
                'current' => $current,
                'limit' => $this->limits['per_day']
            ];
        }
        
        return ['allowed' => true];
    }
    
    private function checkUserMinute(): array
    {
        if (!isset($this->limits['per_user_minute'])) {
            return ['allowed' => true];
        }
        
        $key = "rate_limit:{$this->action}:user:{$this->userId}:minute:" . date('YmdHi');
        $current = (int)Cache::get($key, 0);
        
        if ($current >= $this->limits['per_user_minute']) {
            return [
                'allowed' => false,
                'type' => 'USER_MINUTE',
                'retry_after' => 60 - date('s'),
                'current' => $current,
                'limit' => $this->limits['per_user_minute']
            ];
        }
        
        return ['allowed' => true];
    }
    
    private function checkIpMinute(): array
    {
        if (!isset($this->limits['per_ip_minute'])) {
            return ['allowed' => true];
        }
        
        $key = "rate_limit:{$this->action}:ip:{$this->ip}:minute:" . date('YmdHi');
        $current = (int)Cache::get($key, 0);
        
        if ($current >= $this->limits['per_ip_minute']) {
            return [
                'allowed' => false,
                'type' => 'IP_MINUTE',
                'retry_after' => 60 - date('s'),
                'current' => $current,
                'limit' => $this->limits['per_ip_minute']
            ];
        }
        
        return ['allowed' => true];
    }
    
    private function incrementCounters(): void
    {
        $keys = [
            "rate_limit:{$this->action}:minute:" . date('YmdHi') => 60,
            "rate_limit:{$this->action}:hour:" . date('YmdH') => 3600,
            "rate_limit:{$this->action}:day:" . date('Ymd') => 86400,
        ];
        
        if (auth()->check()) {
            $keys["rate_limit:{$this->action}:user:{$this->userId}:minute:" . date('YmdHi')] = 60;
        }
        
        $keys["rate_limit:{$this->action}:ip:{$this->ip}:minute:" . date('YmdHi')] = 60;
        
        foreach ($keys as $key => $ttl) {
            Cache::increment($key);
            Cache::expire($key, $ttl);
        }
    }
    
    /**
     * Obtener estadísticas actuales
     */
    public function getStats(): array
    {
        return [
            'global_minute' => (int)Cache::get("rate_limit:{$this->action}:minute:" . date('YmdHi'), 0),
            'global_hour' => (int)Cache::get("rate_limit:{$this->action}:hour:" . date('YmdH'), 0),
            'global_day' => (int)Cache::get("rate_limit:{$this->action}:day:" . date('Ymd'), 0),
            'user_minute' => (int)Cache::get("rate_limit:{$this->action}:user:{$this->userId}:minute:" . date('YmdHi'), 0),
            'ip_minute' => (int)Cache::get("rate_limit:{$this->action}:ip:{$this->ip}:minute:" . date('YmdHi'), 0),
            'limits' => $this->limits
        ];
    }
}