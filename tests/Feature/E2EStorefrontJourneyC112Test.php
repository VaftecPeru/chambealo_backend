<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * C-112: Suite E2E automatizada de flujo completo de tienda:
 * Acceso -> Catálogo y Detalle -> Carrito/Pedido y Stock -> Checkout PayPal.
 */
class E2EStorefrontJourneyC112Test extends TestCase
{
    use RefreshDatabase;

    private function seedCatalog(int $tenantId = 1): array
    {
        $vendor = User::factory()->vendor()->create(['tenant_id' => $tenantId]);
        $brand = Brand::create([
            'user_id' => $vendor->user_id,
            'brand_name' => 'FerrePro E2E',
            'visibility_status' => 'visible',
        ]);
        $category = Category::create([
            'name' => 'Herramientas E2E',
            'slug' => 'herramientas-e2e-' . uniqid(),
        ]);

        $drill = Product::create([
            'brand_id' => $brand->brand_id,
            'category_id' => $category->category_id,
            'name' => 'Taladro E2E 20V',
            'slug' => 'taladro-e2e-' . uniqid(),
            'description' => 'Taladro profesional para pruebas E2E',
            'price' => 150.00,
            'stock' => 10,
            'status' => 'active',
        ]);

        $helmet = Product::create([
            'brand_id' => $brand->brand_id,
            'category_id' => $category->category_id,
            'name' => 'Casco Seguridad E2E',
            'slug' => 'casco-e2e-' . uniqid(),
            'description' => 'Casco dieléctrico certificado',
            'price' => 50.00,
            'stock' => 5,
            'status' => 'active',
        ]);

        $outOfStock = Product::create([
            'brand_id' => $brand->brand_id,
            'category_id' => $category->category_id,
            'name' => 'Guantes Agotados E2E',
            'slug' => 'guantes-e2e-' . uniqid(),
            'price' => 25.00,
            'stock' => 0,
            'status' => 'active',
        ]);

        return compact('vendor', 'brand', 'category', 'drill', 'helmet', 'outOfStock');
    }

    public function test_e2e_access_lifecycle_register_login_profile_and_logout_revocation(): void
    {
        $email = 'e2e_c112_' . uniqid() . '@chambealo.pe';

        // 1. Register new user
        $this->postJson('/api/register', [
            'name' => 'Cliente E2E C112',
            'email' => $email,
            'password' => 'E2ePass12345!',
            'password_confirmation' => 'E2ePass12345!',
        ])->assertCreated();

        // 2. Duplicate email rejected with 422
        $this->postJson('/api/register', [
            'name' => 'Duplicado',
            'email' => $email,
            'password' => 'E2ePass12345!',
            'password_confirmation' => 'E2ePass12345!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        // 3. Invalid password rejected with 401
        $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'ClaveIncorrecta!',
        ])->assertUnauthorized();

        // 4. Valid login issues JWT
        $login = $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'E2ePass12345!',
        ])->assertOk();

        $token = (string) $login->json('access_token');
        $this->assertNotEmpty($token);

        // 5. Profile accessible with Bearer token
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('user.email', $email);

        // 6. Logout revokes token
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout')
            ->assertOk();

        // 7. Reusing revoked token returns 401
        auth()->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/profile')
            ->assertUnauthorized();
    }

    public function test_e2e_catalog_search_filters_and_product_detail(): void
    {
        $catalog = $this->seedCatalog();

        // Categories endpoint
        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('success', true);

        // Catalog list returns all 3 active products with real brand.name
        $list = $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');

        $brands = collect($list->json('data'))->pluck('brand.name')->unique()->values()->all();
        $this->assertSame(['FerrePro E2E'], $brands);

        // Search ?q=Taladro
        $this->getJson('/api/products?q=Taladro')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Taladro E2E 20V');

        // Detail of existing product
        $this->getJson('/api/products/' . $catalog['drill']->product_id)
            ->assertOk()
            ->assertJsonPath('data.name', 'Taladro E2E 20V')
            ->assertJsonPath('data.stock', 10);

        // Detail of non-existent product returns 404
        $this->getJson('/api/products/999999')->assertNotFound();
    }

    public function test_e2e_cart_to_order_stock_reservation_validation_and_cancellation(): void
    {
        $catalog = $this->seedCatalog(1);
        $customer = User::factory()->create(['tenant_id' => 1]);
        $token = auth('api')->login($customer);
        $headers = ['Authorization' => 'Bearer ' . $token];

        // Reject out-of-stock item with 422 without modifying stock
        $this->withHeaders($headers)->postJson('/api/orders', [
            'items' => [['product_id' => $catalog['outOfStock']->product_id, 'quantity' => 1]],
            'shipping_address' => ['city' => 'Lima', 'street' => 'Av. Principal 100'],
            'billing_address' => ['city' => 'Lima', 'street' => 'Av. Principal 100'],
        ])->assertUnprocessable()->assertJsonPath('code', 'insufficient_stock');

        // Create valid order with 2 products + repeated line consolidation (drill: 1+2=3, helmet: 1)
        // Expected total: 3*150 + 1*50 = 500
        $created = $this->withHeaders($headers)->postJson('/api/orders', [
            'items' => [
                ['product_id' => $catalog['drill']->product_id, 'quantity' => 1],
                ['product_id' => $catalog['drill']->product_id, 'quantity' => 2],
                ['product_id' => $catalog['helmet']->product_id, 'quantity' => 1],
            ],
            'shipping_address' => ['city' => 'Lima', 'street' => 'Av. Principal 100'],
            'billing_address' => ['city' => 'Lima', 'street' => 'Av. Principal 100'],
        ])->assertCreated()
            ->assertJsonPath('order.total_amount', 500)
            ->assertJsonPath('order.status', Order::STATUS_CHECKOUT);

        $orderId = (string) $created->json('order.order_id');

        // Stock decremented atomically
        $this->assertSame(7, (int) $catalog['drill']->fresh()->stock);
        $this->assertSame(4, (int) $catalog['helmet']->fresh()->stock);
        $this->assertSame(2, InventoryMovement::where('reference_id', $orderId)->where('type', 'out')->count());

        // Customer can view order in /api/orders and /api/orders/{orderId}
        $this->withHeaders($headers)->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1, 'orders');

        $this->withHeaders($headers)->getJson("/api/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('order.order_id', $orderId);

        // Cancel order restores exact stock and records audit
        $this->withHeaders($headers)->patchJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_CANCELLED);

        $this->assertSame(10, (int) $catalog['drill']->fresh()->stock);
        $this->assertSame(5, (int) $catalog['helmet']->fresh()->stock);

        // Cancelling a second time is rejected (422) and never double-restores stock
        $this->withHeaders($headers)->patchJson("/api/orders/{$orderId}/cancel")
            ->assertUnprocessable();
        $this->assertSame(10, (int) $catalog['drill']->fresh()->stock);
    }

    public function test_e2e_checkout_paypal_session_idempotency_and_cancellation_lock(): void
    {
        $catalog = $this->seedCatalog(1);
        $owner = User::factory()->create(['tenant_id' => 1]);
        $other = User::factory()->create(['tenant_id' => 1]);

        config()->set('payment.default_currency', 'PEN');
        config()->set('payment.paypal_fx.max_age_minutes', 1440);
        config()->set('payment.paypal.client_id', 'CLIENT_E2E');
        config()->set('payment.paypal.client_secret', 'SECRET_E2E');
        Cache::forget('paypal_fx_usd_pen');

        Http::preventStrayRequests();
        Http::fake([
            'https://open.er-api.com/v6/latest/USD' => Http::response([
                'result' => 'success',
                'rates' => ['PEN' => 3.50],
                'time_last_update_utc' => now()->toRfc7231String(),
            ], 200),
            '*paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'PP_E2E_TOKEN',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),
            '*paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PAYPAL-E2E-ORDER-1',
                'status' => 'CREATED',
                'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-E2E-ORDER-1']],
            ], 201),
        ]);

        // Create order as owner
        $ownerHeaders = ['Authorization' => 'Bearer ' . auth('api')->login($owner)];
        $orderRes = $this->withHeaders($ownerHeaders)->postJson('/api/orders', [
            'items' => [['product_id' => $catalog['drill']->product_id, 'quantity' => 1]],
            'shipping_address' => ['city' => 'Lima'],
            'billing_address' => ['city' => 'Lima'],
        ])->assertCreated();

        $orderDbId = (int) $orderRes->json('order.id');
        $orderId = (string) $orderRes->json('order.order_id');

        // Another user cannot start checkout session for owner's order
        $otherHeaders = ['Authorization' => 'Bearer ' . auth('api')->login($other)];
        $this->withHeaders($otherHeaders)->postJson('/api/payment/session', [
            'gateway' => 'paypal',
            'order_id' => $orderDbId,
        ])->assertNotFound();

        // Owner starts PayPal checkout session -> 200
        $this->withHeaders($ownerHeaders)->postJson('/api/payment/session', [
            'gateway' => 'paypal',
            'order_id' => $orderDbId,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.gateway_id', 'PAYPAL-E2E-ORDER-1')
            ->assertJsonPath('data.currency', 'USD');

        // Duplicate session while pending -> 409 conflict
        $this->withHeaders($ownerHeaders)->postJson('/api/payment/session', [
            'gateway' => 'paypal',
            'order_id' => $orderDbId,
        ])->assertStatus(409);

        // Order with pending payment cannot be cancelled -> 409 conflict (stock stays reserved)
        $this->withHeaders($ownerHeaders)->patchJson("/api/orders/{$orderId}/cancel")
            ->assertStatus(409);
        $this->assertSame(9, (int) $catalog['drill']->fresh()->stock);
    }
}
