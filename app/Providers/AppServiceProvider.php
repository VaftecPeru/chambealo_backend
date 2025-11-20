<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        // Forzar HTTPS en producción
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Log de queries lentos (opcional)
        DB::whenQueryingForLongerThan(500, function ($connection, $query, $time) {
            Log::warning("Slow Query Detected", [
                'connection' => $connection->getName(),
                'query' => $query,
                'time' => $time,
            ]);
        });
    }
}
