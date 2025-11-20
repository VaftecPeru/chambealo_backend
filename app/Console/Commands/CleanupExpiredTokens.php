<?php

namespace App\Console\Commands;

use App\Models\RefreshToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupExpiredTokens extends Command
{
    protected $signature = 'tokens:cleanup';
    protected $description = 'Clean up expired refresh tokens';

    public function handle()
    {
        $deleted = RefreshToken::where('expires_at', '<', now())->delete();

        $this->info("Cleaned up {$deleted} expired tokens.");

        // Log de la acción
        Log::info("Expired tokens cleanup completed", ['deleted_count' => $deleted]);
        return 0;
    }
}
