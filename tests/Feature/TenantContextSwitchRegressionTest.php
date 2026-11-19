<?php

namespace Tests\Feature;

use App\Http\Middleware\TenantMiddleware;
use Illuminate\Http\Request;
use Tests\TestCase;

class TenantContextSwitchRegressionTest extends TestCase
{
    public function test_consecutive_authenticated_requests_resolve_their_own_tenant(): void
    {
        $middleware = new TenantMiddleware();

        foreach ([2, 3, 2] as $tenantId) {
            $request = Request::create('/api/orders', 'GET', ['tenant_id' => 999]);
            $request->setUserResolver(fn ($guard = null) => (object) [
                'user_id' => 42,
                'tenant_id' => $tenantId,
            ]);

            $response = $middleware->handle($request, function () {
                return response()->json(['tenant_id' => app('tenant_id')]);
            });

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame($tenantId, $response->getData(true)['tenant_id']);
        }
    }

    public function test_missing_tenant_after_authorized_request_does_not_reuse_prior_tenant(): void
    {
        $middleware = new TenantMiddleware();

        $authorized = Request::create('/api/orders', 'GET');
        $authorized->setUserResolver(fn ($guard = null) => (object) [
            'user_id' => 42,
            'tenant_id' => 2,
        ]);
        $middleware->handle($authorized, fn () => response()->json(['ok' => true]));

        $denied = Request::create('/api/orders', 'GET', ['tenant_id' => 2]);
        $denied->setUserResolver(fn ($guard = null) => (object) ['user_id' => 43]);

        $executed = false;
        $response = $middleware->handle($denied, function () use (&$executed) {
            $executed = true;
            return response()->json(['ok' => true]);
        });

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($executed);
        $this->assertFalse(app()->bound('tenant_id'));
    }
}
