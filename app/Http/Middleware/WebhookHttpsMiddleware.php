<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * WebhookHttpsMiddleware
 * Valida que los webhooks lleguen por HTTPS en producción
 * Requiere configuración: PAYMENT_REQUIRE_HTTPS=true
 */
class WebhookHttpsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Solo validar en producción si está configurado
        if (app()->environment('production') && config('payment.security.require_https_webhooks', true)) {
            if (!$request->isSecure()) {
                Log::warning('Webhook received without HTTPS', [
                    'ip' => $request->ip(),
                    'gateway' => $request->route('gateway') ?? 'unknown',
                    'timestamp' => now(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'HTTPS requerido',
                ], 403);
            }
        }

        return $next($request);
    }
}
