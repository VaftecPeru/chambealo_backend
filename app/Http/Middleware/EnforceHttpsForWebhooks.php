<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EnforceHttpsForWebhooks
{
    public function handle(Request $request, Closure $next)
    {
        // Verificar que la conexión es HTTPS
        if (!$request->secure() && !$request->isSecure()) {
            Log::warning('Webhook recibido por HTTP (inseguro)', [
                'gateway' => $request->path(),
                'ip' => $request->ip(),
                'method' => $request->method()
            ]);
            
            return response()->json([
                'error' => 'HTTPS required for webhooks'
            ], 403);
        }
        
        $request->attributes->set('https_verified', true);
        $request->attributes->set('tls_version', $request->server('SSL_PROTOCOL') ?? 'TLS v1.2+');
        
        return $next($request);
    }
}
