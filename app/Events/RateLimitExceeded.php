<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RateLimitExceeded
{
    use Dispatchable, SerializesModels;
    
    public string $action;
    public string $userId;
    public string $ip;
    public string $limitType;
    public int $retryAfter;
    
    public function __construct(string $action, string $userId, string $ip, string $limitType, int $retryAfter)
    {
        $this->action = $action;
        $this->userId = $userId;
        $this->ip = $ip;
        $this->limitType = $limitType;
        $this->retryAfter = $retryAfter;
    }
}