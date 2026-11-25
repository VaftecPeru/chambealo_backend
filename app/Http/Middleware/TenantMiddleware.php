<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TenantMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user() ?? auth('api')->user();
        $tenantId = $user?->tenant_id !== null ? (int) $user->tenant_id : null;

        app()->forgetInstance('tenant_id');
        app()->bind('tenant_id', static fn () => $tenantId);
        $request->attributes->set('tenant_id', $tenantId);

        return $next($request);
    }

    public function terminate($request, $response): void
    {
        app()->forgetInstance('tenant_id');
    }
}