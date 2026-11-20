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

    public function test_unauthenticated_legacy_payment_request_is_rejected_before_processing(): void
    {
        // No external payment request is made: auth middleware must reject first.
        $this->postJson('/api/process-payment', [
            'amount' => 1,
            'order_id' => 999999,
        ])->assertUnauthorized();
    }

    public function test_legacy_payment_endpoint_is_not_available_via_get(): void
    {
        $this->getJson('/api/process-payment')->assertStatus(405);
    }
}
