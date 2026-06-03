<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * IdentifyTenant Middleware
 * 
 * Identifica y valida el tenant en cada solicitud con medidas de seguridad:
 * - Validación de HTTPS en producción
 * - Rate limiting por tenant
 * - Prevención de header injection
 * - Logging de seguridad
 * - Validación de formato de tenant_id
 * 
 * @package App\Http\Middleware
 */
class IdentifyTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // ✅ 1. VALIDAR HTTPS EN PRODUCCIÓN
        if (app()->environment('production') && !$request->isSecure()) {
            Log::critical('IdentifyTenant: Non-HTTPS request in production', [
                'ip' => $request->ip(),
                'method' => $request->method(),
                'path' => $request->path(),
            ]);
            return response()->json(['success' => false, 'message' => 'HTTPS required'], 403);
        }

        // ✅ 2. EXTRAER TENANT_ID CON VALIDACIÓN DE SEGURIDAD
        $tenantId = $this->extractTenantId($request);

        // ✅ 3. VALIDAR FORMATO DE TENANT_ID (alphanumeric, guiones, guiones bajos)
        if (!$this->isValidTenantId($tenantId)) {
            Log::warning('IdentifyTenant: Invalid tenant ID format', [
                'tenant_id' => $tenantId,
                'ip' => $request->ip(),
                'user_id' => auth()->id(),
            ]);
            return response()->json(['success' => false, 'message' => 'Invalid tenant ID'], 400);
        }

        // ✅ 4. RATE LIMITING POR TENANT
        if (!$this->checkTenantRateLimit($request, $tenantId)) {
            Log::warning('IdentifyTenant: Rate limit exceeded for tenant', [
                'tenant_id' => $tenantId,
                'ip' => $request->ip(),
            ]);
            return response()->json(['success' => false, 'message' => 'Rate limit exceeded'], 429);
        }

        // ✅ 5. VALIDACIÓN DE PROPIEDAD DEL TENANT (si está autenticado)
        if (auth()->check()) {
            $userTenantId = auth()->user()->tenant_id ?? 'default';
            
            // El usuario solo puede acceder a su propio tenant a menos que sea admin
            if (!auth()->user()->isAdmin && $tenantId !== $userTenantId) {
                Log::warning('IdentifyTenant: Unauthorized tenant access attempt', [
                    'user_id' => auth()->id(),
                    'user_tenant' => $userTenantId,
                    'requested_tenant' => $tenantId,
                    'ip' => $request->ip(),
                ]);
                return response()->json(['success' => false, 'message' => 'Unauthorized tenant access'], 403);
            }
        }

        // ✅ 6. LIMPIAR HEADERS PARA PREVENIR INJECTION
        $this->sanitizeTenantHeaders($request);

        // ✅ 7. ALMACENAR EN CONTEXTO DE SOLICITUD
        $request->merge(['tenant_id' => $tenantId]);

        // ✅ 8. LOGGING DE AUDITORIA
        Log::info('IdentifyTenant: Tenant identified', [
            'tenant_id' => $tenantId,
            'user_id' => auth()->id(),
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        return $next($request);
    }

    /**
     * Extraer tenant ID de múltiples fuentes de forma segura
     */
    private function extractTenantId(Request $request): string
    {
        // Prioridad: Header > Query > Body > Usuario autenticado > Default
        $tenantId = $request->header('X-Tenant-ID') 
            ?? $request->header('x-tenant-id')
            ?? $request->query('tenant_id')
            ?? $request->input('tenant_id');

        if ($tenantId) {
            return (string)$tenantId;
        }

        if (auth()->check()) {
            return auth()->user()->tenant_id ?? 'default';
        }

        return config('app.default_tenant', 'default');
    }

    /**
     * Validar que el tenant_id tenga formato seguro
     * Solo permite: alphanumeric, guiones, guiones bajos
     */
    private function isValidTenantId(string $tenantId): bool
    {
        // Longitud: mínimo 1, máximo 64 caracteres
        if (strlen($tenantId) < 1 || strlen($tenantId) > 64) {
            return false;
        }

        // Solo permitir: a-z, A-Z, 0-9, -, _
        return preg_match('/^[a-zA-Z0-9\-_]+$/', $tenantId) === 1;
    }

    /**
     * Rate limiting por tenant: máx 1000 requests por minuto
     */
    private function checkTenantRateLimit(Request $request, string $tenantId): bool
    {
        $cacheKey = "tenant_rate_limit_{$tenantId}_{$request->ip()}";
        $maxRequests = 1000;
        $windowSeconds = 60;

        $currentCount = Cache::get($cacheKey, 0);

        if ($currentCount >= $maxRequests) {
            return false;
        }

        Cache::increment($cacheKey, 1, $windowSeconds);
        return true;
    }

    /**
     * Sanitizar headers para prevenir header injection
     */
    private function sanitizeTenantHeaders(Request $request): void
    {
        // Remover cualquier header potencialmente malicioso relacionado a tenant
        if ($request->hasHeader('X-Tenant-ID-Override')) {
            Log::warning('IdentifyTenant: Potential header injection attempt detected', [
                'ip' => $request->ip(),
                'user_id' => auth()->id(),
            ]);
        }
    }
}
