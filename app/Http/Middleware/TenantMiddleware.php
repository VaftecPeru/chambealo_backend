<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;

class TenantMiddleware
{
    public function handle($request, Closure $next)
    {
        // Por ahora, usar tenant_id = 1 para pruebas
        $tenantId = 1;
        
        // Inyectar en el contenedor
        app()->instance('tenant_id', $tenantId);
        
        // También agregar al request
        $request->merge(['tenant_id' => $tenantId]);
        
        return $next($request);
    }
}