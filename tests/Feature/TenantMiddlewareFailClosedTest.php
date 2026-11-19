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

    public function test_invalid_tenant_identifiers_are_denied_before_downstream_execution(): void
    {
        foreach ([0, -1, 'abc', '1 OR 1=1', ''] as $invalidTenantId) {
            $request = Request::create('/api/orders', 'GET', ['tenant_id' => 777]);
            $request->setUserResolver(fn ($guard = null) => (object) [
                'user_id' => 42,
                'tenant_id' => $invalidTenantId,
            ]);

            $downstreamCalled = false;
            $response = (new TenantMiddleware())->handle($request, function () use (&$downstreamCalled) {
                $downstreamCalled = true;
                return response()->json(['allowed' => true]);
            });

            $this->assertSame(403, $response->getStatusCode());
            $this->assertFalse($downstreamCalled, 'Invalid tenant context reached protected route');
        }
    }

    public function test_query_and_body_cannot_override_authenticated_tenant(): void
    {
        $request = Request::create('/api/orders', 'POST', [
            'tenant_id' => 999,
            'store_id' => 999,
        ]);
        $request->query->set('tenant_id', 888);
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

    public function test_denied_request_clears_tenant_context_from_prior_request(): void
    {
        $middleware = new TenantMiddleware();

        $authorized = Request::create('/api/orders', 'GET');
        $authorized->setUserResolver(fn ($guard = null) => (object) [
            'user_id' => 42,
            'tenant_id' => 2,
        ]);
        $response = $middleware->handle($authorized, fn () => response()->json(['allowed' => true]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, app('tenant_id'));

        $denied = Request::create('/api/orders', 'GET', ['tenant_id' => 999]);
        $denied->setUserResolver(fn ($guard = null) => null);
        $response = $middleware->handle($denied, fn () => response()->json(['allowed' => true]));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse(app()->bound('tenant_id'), 'Tenant context leaked across requests');
    }

    public function test_denied_authenticated_request_clears_prior_tenant_context(): void
    {
        app()->instance('tenant_id', 2);

        $request = Request::create('/api/orders', 'GET', ['tenant_id' => 2]);
        $request->setUserResolver(fn ($guard = null) => (object) [
            'user_id' => 99,
            'tenant_id' => null,
        ]);

        $downstreamCalled = false;
        $response = (new TenantMiddleware())->handle($request, function () use (&$downstreamCalled) {
            $downstreamCalled = true;
            return response()->json(['allowed' => true]);
        });

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($downstreamCalled);
        $this->assertFalse(app()->bound('tenant_id'), 'Stale tenant context survived a forbidden request');
    }
}
