<?php

namespace App\Services;

use App\Models\PaymentLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogTransferService
{
    public function storeLog(array $logData, Request $request, ?string $jobId = null): PaymentLog
    {
        try {
            $exists = PaymentLog::where('type', $logData['type'] ?? 'unknown')
                ->where('ip_address', $logData['ip'] ?? $request->ip())
                ->where('logged_at', '>=', now()->subSeconds(5))
                ->exists();

            if ($exists) {
                Log::warning('Log duplicado detectado, omitiendo almacenamiento', $logData);
                return new PaymentLog();
            }

            return PaymentLog::create([
                'type' => $logData['type'] ?? 'system_log',
                'job_id' => $jobId ?? $logData['job_id'] ?? null,
                'order_id' => $logData['order_id'] ?? null,
                'data' => $logData,
                'ip_address' => $logData['ip'] ?? $request->ip(),
                'user_id' => $logData['user_id'] ?? auth()->id(),
                'user_agent' => $logData['user_agent'] ?? $request->userAgent(),
                'session_id' => session()->getId(),
                'logged_at' => $logData['timestamp'] ?? now()
            ]);
        } catch (\Exception $e) {
            Log::critical('Error guardando log en BD', [
                'error' => $e->getMessage(),
                'log_data' => $logData
            ]);
            throw $e;
        }
    }

    public function logProcessStart(Request $request, array $additionalData = []): array
    {
        $logData = array_merge([
            'type' => 'job_process_start',
            'job_id' => uniqid('job_'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => auth()->id(),
            'timestamp' => now()->toIso8601String(),
            'memory_usage' => memory_get_usage(true),
            'request_id' => request()->header('X-Request-ID', uniqid())
        ], $additionalData);

        Log::channel('daily')->info('Job iniciado', $logData);
        $this->storeLog($logData, $request, $logData['job_id']);
        
        return $logData;
    }

    public function logProcessEnd(string $jobId, Request $request, array $result, float $executionTime): void
    {
        $logData = [
            'type' => 'job_process_end',
            'job_id' => $jobId,
            'success' => $result['success'] ?? false,
            'execution_time_ms' => round($executionTime * 1000, 2),
            'memory_peak' => memory_get_peak_usage(true),
            'result_summary' => $result['message'] ?? 'Procesado',
            'timestamp' => now()->toIso8601String()
        ];

        Log::channel('daily')->info('Job finalizado', $logData);
        $this->storeLog($logData, $request, $jobId);
    }

    /**
     * Log frontend connection (nueva funcionalidad)
     * Captura TODAS las conexiones frontend → backend sin interferir
     * 
     * @param Request $request
     * @param Response|null $response
     * @return void
     */
    public function logFrontendConnection(Request $request, ?Response $response = null): void
    {
        try {
            if (!config('logging.log_frontend_connections')) {
                return;
            }

            $logData = [
                'type' => 'frontend_connection',
                'method' => $request->getMethod(),
                'path' => $request->path(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id' => auth()->id(),
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', uniqid('req_')),
                'content_type' => $request->header('Content-Type'),
                'response_code' => $response?->getStatusCode() ?? 0,
                'response_time_ms' => round((microtime(true) - LARAVEL_START) * 1000, 2),
            ];

            // Anonimizar JWT si existe en Authorization header
            if ($token = $this->extractBearerToken($request)) {
                $logData['token_preview'] = $this->anonymizeJwt($token);
            }

            // Nunca guardar datos sensibles en body
            if ($request->isJson() && !$this->isSensitiveEndpoint($request)) {
                $logData['has_json_payload'] = true;
                $logData['json_keys'] = array_keys($request->json()->all());
            }

            // No bloquear si hay error de BD
            try {
                $this->storeLog($logData, $request);
            } catch (\Exception $e) {
                Log::debug('Frontend connection log failed silently', [
                    'error' => $e->getMessage()
                ]);
            }
        } catch (\Exception $e) {
            // Manejar errores silenciosamente sin interrumpir request
            Log::debug('Error logging frontend connection', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Extraer token Bearer del header Authorization
     */
    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if ($header && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    /**
     * Anonimizar JWT: mostrar primeros 8 y últimos 4 caracteres
     * Ej: "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..." -> "eyJhbGc****UVCJ9..."
     */
    private function anonymizeJwt(string $token): string
    {
        if (strlen($token) <= 12) {
            return str_repeat('*', strlen($token));
        }
        
        $start = substr($token, 0, 8);
        $end = substr($token, -4);
        $masked = str_repeat('*', strlen($token) - 12);
        
        return "{$start}{$masked}{$end}";
    }

    /**
     * Determinar si endpoint contiene datos sensibles
     */
    private function isSensitiveEndpoint(Request $request): bool
    {
        $path = $request->path();
        
        $sensitivePatterns = [
            'login',
            'register',
            'password',
            'token',
            'oauth',
            'payment',
            'webhook',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (stripos($path, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
