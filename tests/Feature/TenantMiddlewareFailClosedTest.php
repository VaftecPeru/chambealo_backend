<?php

namespace Tests\Feature;

use App\Http\Middleware\TenantMiddleware;
use Illuminate\Http\Request;
use Tests\TestCase;

class TenantMiddlewareFailClosedTest extends TestCase
{
    public function test_unauthenticated_request_cannot_select_tenant_from_payload(): void
    {
        $request = Request::create('/api/products', 'POST', ['tenant_id' => 1]);
        $request->setUserResolver(fn ($guard = null) => null);

        $response = (new TenantMiddleware())->handle($request, fn () => response()->json(['allowed' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_authenticated_user_without_membership_cannot_spoof_tenant(): void
    {
        $request = Request::create('/api/products', 'POST', ['tenant_id' => 1]);
        $request->setUserResolver(fn ($guard = null) => (object) ['user_id' => 99]);

        $response = (new TenantMiddleware())->handle($request, fn () => response()->json(['allowed' => true]));

        $this->assertSame(403, $response->getStatusCode());
    }
    public function test_authenticated_tenant_context_ignores_spoofed_payload(): void
    {
        $request = Request::create('/api/products', 'POST', ['tenant_id' => 999]);
        $request->setUserResolver(fn ($guard = null) => (object) [
            'user_id' => 42,
            'tenant_id' => 2,
        ]);

        $response = (new TenantMiddleware())->handle($request, function () {
            return response()->json(['resolved_tenant_id' => app('tenant_id')]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $response->getData(true)['resolved_tenant_id']);
    }
}
