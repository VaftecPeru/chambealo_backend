<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LegacyPaymentRouteSecurityTest extends TestCase
{
    public function test_legacy_payment_endpoint_requires_authentication_and_active_account(): void
    {
        $route = Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/api/process-payment', 'POST')
        );

        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth:api', $middleware);
        $this->assertContains('active', $middleware);
        $this->assertContains('throttle:30,1', $middleware);
    }

    public function test_legacy_payment_endpoint_is_not_available_via_get(): void
    {
        $this->getJson('/api/process-payment')->assertStatus(405);
    }
}
