<?php

namespace App\Listeners;

use App\Events\RateLimitExceeded;
use Illuminate\Support\Facades\Log;

class LogRateLimitExceeded
{
    public function handle(RateLimitExceeded $event): void
    {
        Log::channel('payment')->warning('Rate limit exceeded event', [
            'action' => $event->action,
            'user_id' => $event->userId,
            'ip' => $event->ip,
            'limit_type' => $event->limitType,
            'retry_after' => $event->retryAfter
        ]);
        
        // Aquí podrías enviar email, slack, etc.
    }
}