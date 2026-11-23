<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Security regression gates for #9 and #7.
 * These are deliberately fail-closed checks: they should remain red until
 * tenant resolution and legacy payment authentication are implemented.
 * No external services, credentials, or payments are used.
 */
class TenantBoundarySecurityTest extends TestCase
{
    public function test_tenant_middleware_does_not_assign_a_global_fixed_tenant(): void
    {
        $source = file_get_contents(app_path('Http/Middleware/TenantMiddleware.php'));

        $this->assertIsString($source);
        $this->assertDoesNotMatchRegularExpression(
            '/\\$tenantId\\s*=\\s*1\\s*;/',
            $source,
            'P0: a fixed tenant_id=1 is not a valid multi-tenant boundary.'
        );
    }

    public function test_legacy_process_payment_is_not_exposed_in_public_routes(): void
    {
        $source = file_get_contents(base_path('routes/api.php'));

        $this->assertIsString($source);
        $publicStart = strpos($source, "// Public routes");
        $webhookStart = strpos($source, "// Unified Payment Webhooks");

        $this->assertNotFalse($publicStart);
        $this->assertNotFalse($webhookStart);
        $publicRoutes = substr($source, $publicStart, $webhookStart - $publicStart);

        $this->assertStringNotContainsString(
            "Route::post('/process-payment'",
            $publicRoutes,
            'P0: legacy payment processing must not be publicly callable without an explicit security design.'
        );
    }
}
