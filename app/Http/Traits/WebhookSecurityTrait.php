<?php

namespace App\Http\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * WebhookSecurityTrait
 * Proporciona métodos de seguridad comunes para validación de webhooks
 * 
 * Características:
 * - Validación de HTTPS en producción
 * - Prevención de replay attacks
 * - Rate limiting por IP
 * - Verificación de tiempos
 */
trait WebhookSecurityTrait
{
    /**
     * Validar que la solicitud sea HTTPS (en producción)
     * 
     * @param Request $request
     * @return array ['verified' => bool, 'tls_version' => string]
     */
    protected function checkHttps(Request $request): array
    {
        $isHttps = $request->isSecure();
        $tlsVersion = $request->server('HTTP_SSL_PROTOCOL') ?? $request->server('HTTP_X_FORWARDED_PROTO') ?? 'unknown';

        if (!$isHttps && app()->environment('production')) {
            Log::warning('Webhook received without HTTPS', [
                'ip' => $request->ip(),
                'gateway' => $this->getCurrentGateway() ?? 'unknown',
                'timestamp' => now(),
            ]);
        }

        return [
            'verified' => $isHttps,
            'tls_version' => $tlsVersion,
        ];
    }

    /**
     * Prevenir replay attacks usando X-Request-Id
     * Almacena en cache los IDs de solicitud ya procesados
     * 
     * @param Request $request
     * @param string $gateway
     * @param int $windowSeconds Ventana de tiempo en segundos
     * @return bool True si es un request válido (no es replay)
     */
    protected function checkReplayAttack(Request $request, string $gateway, int $windowSeconds = 300): bool
    {
        $requestId = $request->header('X-Request-Id') ?? 
                    $request->header('x-request-id') ?? 
                    $request->input('request_id') ?? 
                    null;

        if (!$requestId) {
            Log::info('Webhook: No X-Request-Id found', ['gateway' => $gateway]);
            return true; // Permitir si no hay ID
        }

        $cacheKey = "webhook_replay_{$gateway}_{$requestId}";

        if (Cache::has($cacheKey)) {
            Log::warning('Potential replay attack detected', [
                'gateway' => $gateway,
                'request_id' => $requestId,
                'ip' => $request->ip(),
            ]);
            return false;
        }

        // Almacenar en cache
        Cache::put($cacheKey, true, $windowSeconds);
        return true;
    }

    /**
     * Validar ventana de tiempo de timestamp (anti-replay)
     * Típicamente usado para webhooks con timestamp
     * 
     * @param int|string $timestamp Timestamp Unix
     * @param int $windowSeconds Ventana permitida en segundos
     * @return bool
     */
    protected function isTimestampValid($timestamp, int $windowSeconds = 300): bool
    {
        $timestamp = (int)$timestamp;
        $now = now()->timestamp;
        $diff = abs($now - $timestamp);

        if ($diff > $windowSeconds) {
            Log::warning('Webhook timestamp outside valid window', [
                'timestamp' => $timestamp,
                'current' => $now,
                'diff_seconds' => $diff,
                'window_seconds' => $windowSeconds,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Rate limiting por IP para webhooks
     * 
     * @param Request $request
     * @param string $gateway
     * @param int $maxRequests Máximo de requests
     * @param int $windowSeconds Ventana de tiempo
     * @return array ['allowed' => bool, 'remaining' => int]
     */
    protected function checkRateLimitByIp(Request $request, string $gateway, int $maxRequests = 100, int $windowSeconds = 60): array
    {
        $ip = $request->ip();
        $cacheKey = "webhook_ratelimit_{$gateway}_{$ip}";
        
        $currentCount = (int)Cache::get($cacheKey, 0);

        if ($currentCount >= $maxRequests) {
            Log::warning('Rate limit exceeded for gateway webhook', [
                'gateway' => $gateway,
                'ip' => $ip,
                'requests' => $currentCount,
            ]);
            return ['allowed' => false, 'remaining' => 0];
        }

        Cache::put($cacheKey, $currentCount + 1, $windowSeconds);

        return ['allowed' => true, 'remaining' => $maxRequests - ($currentCount + 1)];
    }

    /**
     * Registrar intento fallido de webhook
     * 
     * @param Request $request
     * @param string $gateway
     * @param string $reason
     * @param array $additionalData
     * @return void
     */
    protected function logFailedWebhookAttempt(
        Request $request,
        string $gateway,
        string $reason,
        array $additionalData = []
    ): void {
        Log::warning("Failed webhook validation: {$reason}", array_merge([
            'gateway' => $gateway,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now(),
            'payload_size' => strlen($request->getContent()),
        ], $additionalData));
    }

    /**
     * Registrar webhook procesado exitosamente
     * 
     * @param string $gateway
     * @param array $data
     * @return void
     */
    protected function logSuccessfulWebhook(string $gateway, array $data): void
    {
        Log::info("Webhook processed successfully: {$gateway}", array_merge([
            'gateway' => $gateway,
            'timestamp' => now(),
        ], $data));
    }

    /**
     * Obtener el gateway actual (helper)
     * 
     * @return string|null
     */
    protected function getCurrentGateway(): ?string
    {
        return request()->route('gateway') ?? null;
    }

    /**
     * Validar que el IP esté en whitelist (opcional, por si se necesita)
     * 
     * @param Request $request
     * @param array $whitelist IPs permitidas
     * @return bool
     */
    protected function isIpWhitelisted(Request $request, array $whitelist): bool
    {
        if (empty($whitelist)) {
            return true; // Sin whitelist, permitir todos
        }

        $ip = $request->ip();
        return in_array($ip, $whitelist);
    }
}
