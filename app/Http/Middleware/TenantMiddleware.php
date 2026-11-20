<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TenantMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Clear any tenant context left by an earlier request in this process.
        app()->forgetInstance('tenant_id');

        // Never trust a tenant supplied in a query string or request body.
        // Until tenant membership is modeled and verified, deny unresolved contexts.
        $user = $request->user('api') ?? $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $rawTenantId = $user->tenant_id ?? null;
        // Reject non-scalar and boolean values before integer validation.
        // PHP's integer filter can otherwise coerce true to tenant 1.
        if (!is_int($rawTenantId) && !is_string($rawTenantId)) {
            return response()->json(['message' => 'Tenant access not configured.'], 403);
        }

        $tenantId = filter_var($rawTenantId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($tenantId === false || $tenantId === null) {
            return response()->json(['message' => 'Tenant access not configured.'], 403);
        }

        app()->instance('tenant_id', (int) $tenantId);

        return $next($request);
    }
}
