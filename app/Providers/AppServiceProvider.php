<?php
// app/Providers/AppServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        if (env('APP_ENV') === 'production') {
            URL::forceScheme('https');
        }

        // 🔥 TEMPORAL: Comentar el código problemático
        /*
        DB::whenQueryingForLongerThan(500, function ($connection, $query, $time) {
            Log::warning("Slow Query Detected", [
                'connection' => $connection->getName(),
                'query' => $query,
                'time' => $time,
            ]);
        });
        */
    }
}