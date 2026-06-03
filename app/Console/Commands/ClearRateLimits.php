<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearRateLimits extends Command
{
    protected $signature = 'rate-limits:clear {--action= : Limpiar solo una acción específica}';
    protected $description = 'Clear all rate limits from cache';
    
  public function handle()
{
    Cache::flush();

    $this->info('Cache cleared successfully.');

    return Command::SUCCESS;
}
}