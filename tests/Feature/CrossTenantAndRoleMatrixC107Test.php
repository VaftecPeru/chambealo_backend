<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C-107: Matriz automatizada de pruebas cross-tenant y permisos por rol.
 *
 * Cubre:
 * - Sin sesión -> 401 en rutas protegidas.
 * - Permisos por rol (customer / vendor / admin) -> 403 vs 200/201.
 * - Tenant falsificado (header X-Tenant-Id, query y body tenant_id) ignorado/rechazado.
 * - Vendedor de tienda A bloqueado frente a recursos de tienda B.
 * - Admin legítimo conserva CRUD completo dentro de su tenant.
 * - Cambio secuencial de tenant entre requests sin fuga de contexto.
 * - Gate explícito de usuario sin tenant (403) pendiente de C-106 (PR #12).
 */
class CrossTenantAndRoleMatrixC107Test extends TestCase
{
    use RefreshDatabase;

    private function asUser(User $user, array $extraHeaders = []): static
    {
        $token = auth('api')->login($user);

        return $this->withHeaders(array_merge([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ], $extraHeaders));
    }

    private function createStoreFixture(User $owner, int $tenantId, string $suffix): array
    {
        $brand = Brand::create([
            'user_id' => $owner->user_id,
            'brand_name' => "Marca {$suffix}",
            'visibility_status' => 'visible',
        ]);
        DB::table('brands')->where('brand_id', $brand->brand_id)->update(['tenant_id' => $tenantId]);

        $category = Category::create([
            'name' => "Categoria {$suffix}",
            'slug' => "cat-c107-{$suffix}-" . uniqid(),
        ]);
        DB::table('categories')->where('category_id', $category->category_id)->update(['tenant_id' => $tenantId]);

        $product = Product::create([
            'brand_id' => $brand->brand_id,
            'category_id' => $category->category_id,
            'name' => "Producto {$suffix}",
            'slug' => "prod-c107-{$suffix}-" . uniqid(),
            'price' => 100.00,
            'stock' => 20,
            'status' => 'active',
        ]);
        DB::table('products')->where('product_id', $product->product_id)->update(['tenant_id' => $tenantId]);
        $product->refresh();

        return compact('brand', 'category', 'product');
    }

    private function createOrderFixture(User $customer, Product $product, int $tenantId, string $status = Order::STATUS_CHECKOUT): Order
    {
        return Order::create([
            'order_id' => 'ORD-C107-' . strtoupper(uniqid()),
            'tenant_id' => $tenantId,
            'user_id' => $customer->user_id,
            'total_amount' => 100.00,
            'taxes' => 0,
            'shipping_cost' => 0,
            'status' => $status,
            'items' => [[
                'product_id' => $product->product_id,
                'name' => $product->name,
                'price' => 100.00,
                'quantity' => 1,
                'subtotal' => 100.00,
            ]],
            'shipping_address' => ['city' => 'Lima'],
            'billing_address' => ['city' => 'Lima'],
            'discount' => 0,
        ]);
    }

    public function test_unauthenticated_requests_return_401_across_protected_endpoints(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->putJson('/api/profile', ['full_name' => 'Anon'])->assertUnauthorized();
        $this->getJson('/api/orders')->assertUnauthorized();
        $this->postJson('/api/orders', [])->assertUnauthorized();
        $this->postJson('/api/products', [])->assertUnauthorized();
        $this->postJson('/api/categories', [])->assertUnauthorized();
        $this->getJson('/api/inventory/movements')->assertUnauthorized();
        $this->postJson('/api/inventory/movements', [])->assertUnauthorized();
        $this->getJson('/api/admin/audits')->assertUnauthorized();
        $this->postJson('/api/payment/session', [])->assertUnauthorized();
        $this->postJson('/api/process-payment', [])->assertUnauthorized();
    }

    public function test_role_matrix_enforces_403_for_customer_and_vendor_on_restricted_routes(): void
    {
        $customer = User::factory()->create(['tenant_id' => 1]);
        $vendor = User::factory()->vendor()->create(['tenant_id' => 1]);
        $admin = User::factory()->admin()->create(['tenant_id' => 1]);

        $store = $this->createStoreFixture($vendor, 1, 'role');
        $order = $this->createOrderFixture($customer, $store['product'], 1, Order::STATUS_PAID);

        // Customer: allowed on profile & own orders, forbidden on vendor/admin routes
        $this->asUser($customer)->getJson('/api/profile')->assertOk();
        $this->asUser($customer)->getJson('/api/orders')->assertOk();
        $this->asUser($customer)->postJson('/api/products', [])->assertForbidden();
        $this->asUser($customer)->getJson('/api/inventory/movements')->assertForbidden();
        $this->asUser($customer)->postJson('/api/inventory/movements', [])->assertForbidden();
        $this->asUser($customer)->postJson('/api/categories', ['name' => 'Cat', 'slug' => 'cat-forbidden'])->assertForbidden();
        $this->asUser($customer)->getJson('/api/admin/audits')->assertForbidden();
        $this->asUser($customer)->patchJson("/api/orders/{$order->order_id}/status", ['status' => 'shipped'])->assertForbidden();

        // Vendor: forbidden on admin-only routes
        $this->asUser($vendor)->postJson('/api/categories', ['name' => 'Cat V', 'slug' => 'cat-vendor'])->assertForbidden();
        $this->asUser($vendor)->putJson('/api/categories/' . $store['category']->category_id, ['name' => 'Edit V'])->assertForbidden();
        $this->asUser($vendor)->deleteJson('/api/categories/' . $store['category']->category_id)->assertForbidden();
        $this->asUser($vendor)->getJson('/api/admin/audits')->assertForbidden();
        $this->asUser($vendor)->postJson('/api/products/' . $store['product']->product_id . '/image', [])->assertForbidden();

        // Admin: allowed on admin-only routes
        $this->asUser($admin)->getJson('/api/admin/audits')->assertOk();
    }

    public function test_spoofed_tenant_headers_query_and_body_are_ignored_and_cross_tenant_access_is_denied(): void
    {
        $adminT1 = User::factory()->admin()->create(['tenant_id' => 1]);
        $customerT1 = User::factory()->create(['tenant_id' => 1]);
        $adminT2 = User::factory()->admin()->create(['tenant_id' => 2]);
        $customerT2 = User::factory()->create(['tenant_id' => 2]);

        $storeT1 = $this->createStoreFixture($adminT1, 1, 'spoof-t1');
        $storeT2 = $this->createStoreFixture($adminT2, 2, 'spoof-t2');
        $orderT2 = $this->createOrderFixture($customerT2, $storeT2['product'], 2, Order::STATUS_PAID);

        $auditT2 = app(AuditService::class)->record(
            'Product',
            $storeT2['product']->product_id,
            'created',
            null,
            ['name' => $storeT2['product']->name],
            'Audit T2',
            $adminT2->user_id,
            2,
        );

        $spoofHeaders = ['X-Tenant-Id' => '2'];

        // Customer T1 spoofing tenant 2 cannot view or cancel T2 order
        $this->asUser($customerT1, $spoofHeaders)
            ->getJson("/api/orders/{$orderT2->order_id}?tenant_id=2")
            ->assertNotFound();

        $this->asUser($customerT1, $spoofHeaders)
            ->patchJson("/api/orders/{$orderT2->order_id}/cancel", ['tenant_id' => 2])
            ->assertNotFound();

        // Creating an order with spoofed tenant_id=2 in header/body MUST persist tenant_id=1
        $createdOrder = $this->asUser($customerT1, $spoofHeaders)
            ->postJson('/api/orders?tenant_id=2', [
                'tenant_id' => 2,
                'items' => [['product_id' => $storeT1['product']->product_id, 'quantity' => 1]],
                'shipping_address' => ['city' => 'Lima'],
                'billing_address' => ['city' => 'Lima'],
            ])
            ->assertCreated();

        $this->assertSame(1, (int) $createdOrder->json('order.tenant_id'));

        // Admin T1 spoofing tenant 2 cannot update T2 order status, adjust T2 stock, or read T2 audits
        $this->asUser($adminT1, $spoofHeaders)
            ->patchJson("/api/orders/{$orderT2->order_id}/status?tenant_id=2", [
                'status' => 'shipped',
                'tenant_id' => 2,
            ])
            ->assertNotFound();

        $this->asUser($adminT1, $spoofHeaders)
            ->postJson('/api/inventory/movements?tenant_id=2', [
                'tenant_id' => 2,
                'product_id' => $storeT2['product']->product_id,
                'type' => 'in',
                'quantity' => 5,
                'reason' => 'Intento cross-tenant',
            ])
            ->assertNotFound();

        $this->asUser($adminT1, $spoofHeaders)
            ->getJson('/api/admin/audits/' . $auditT2->audit_id . '?tenant_id=2')
            ->assertNotFound();
    }

    public function test_vendor_from_tenant_a_cannot_access_or_mutate_store_b_resources(): void
    {
        $vendorA = User::factory()->vendor()->create(['tenant_id' => 10]);
        $vendorB = User::factory()->vendor()->create(['tenant_id' => 20]);

        $storeA = $this->createStoreFixture($vendorA, 10, 'va');
        $storeB = $this->createStoreFixture($vendorB, 20, 'vb');

        // Vendor A cannot create product under Vendor B's brand
        $this->asUser($vendorA)->postJson('/api/products', [
            'brand_id' => $storeB['brand']->brand_id,
            'category_id' => $storeA['category']->category_id,
            'name' => 'Producto Ajeno',
            'slug' => 'prod-ajeno-' . uniqid(),
            'price' => 50,
            'stock' => 5,
            'status' => 'active',
        ])->assertForbidden();

        // Vendor A cannot update or delete Vendor B's product
        $this->asUser($vendorA)->putJson('/api/products/' . $storeB['product']->product_id, [
            'name' => 'Hackeado',
        ])->assertForbidden();

        $this->asUser($vendorA)->deleteJson('/api/products/' . $storeB['product']->product_id)
            ->assertForbidden();

        // Vendor A cannot move own product to Vendor B's brand
        $this->asUser($vendorA)->putJson('/api/products/' . $storeA['product']->product_id, [
            'brand_id' => $storeB['brand']->brand_id,
        ])->assertForbidden();

        // Vendor A cannot read or adjust inventory of Vendor B's product
        $this->asUser($vendorA)->getJson('/api/inventory/movements?product_id=' . $storeB['product']->product_id)
            ->assertNotFound();

        $this->asUser($vendorA)->postJson('/api/inventory/movements', [
            'product_id' => $storeB['product']->product_id,
            'type' => 'out',
            'quantity' => 1,
            'reason' => 'Intento no autorizado',
        ])->assertNotFound();

        // /api/my-products only returns Vendor A's own products
        $myProducts = $this->asUser($vendorA)->getJson('/api/my-products')->assertOk();
        $ids = collect($myProducts->json('data'))->pluck('product_id')->all();
        $this->assertContains($storeA['product']->product_id, $ids);
        $this->assertNotContains($storeB['product']->product_id, $ids);
    }

    public function test_legitimate_admin_preserves_full_crud_on_categories_products_inventory_and_audits(): void
    {
        $admin = User::factory()->admin()->create(['tenant_id' => 30]);
        $store = $this->createStoreFixture($admin, 30, 'admin-crud');

        // Category CRUD
        $catCreate = $this->asUser($admin)->postJson('/api/categories', [
            'name' => 'Cat Admin C107',
            'slug' => 'cat-admin-c107-' . uniqid(),
        ])->assertCreated();
        $catId = (int) $catCreate->json('data.category_id');

        $this->asUser($admin)->putJson("/api/categories/{$catId}", [
            'name' => 'Cat Admin C107 Editada',
        ])->assertOk()->assertJsonPath('data.name', 'Cat Admin C107 Editada');

        // Product CRUD
        $prodCreate = $this->asUser($admin)->postJson('/api/products', [
            'brand_id' => $store['brand']->brand_id,
            'category_id' => $catId,
            'name' => 'Prod Admin C107',
            'slug' => 'prod-admin-c107-' . uniqid(),
            'price' => 199.90,
            'stock' => 10,
            'status' => 'active',
        ])->assertCreated();
        $prodId = (int) $prodCreate->json('data.product_id');

        $this->asUser($admin)->putJson("/api/products/{$prodId}", [
            'name' => 'Prod Admin C107 Editado',
            'stock' => 15,
        ])->assertOk()->assertJsonPath('data.stock', 15);

        // Inventory movement
        $this->asUser($admin)->postJson('/api/inventory/movements', [
            'product_id' => $prodId,
            'type' => 'in',
            'quantity' => 5,
            'reason' => 'Reposicion C107',
        ])->assertCreated()->assertJsonPath('data.stock_after', 20);

        // Delete product then delete category
        $this->asUser($admin)->deleteJson("/api/products/{$prodId}")->assertOk();
        $this->asUser($admin)->deleteJson("/api/categories/{$catId}")->assertOk();

        // Audits recorded for tenant 30
        $this->asUser($admin)->getJson('/api/admin/audits?entity_type=Product&entity_id=' . $prodId)
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_sequential_requests_across_tenants_do_not_leak_tenant_context(): void
    {
        $adminT1 = User::factory()->admin()->create(['tenant_id' => 101]);
        $adminT2 = User::factory()->admin()->create(['tenant_id' => 202]);

        $audit1 = app(AuditService::class)->record('Product', 1, 'created', null, ['name' => 'T1'], 'T1', $adminT1->user_id, 101);
        $audit2 = app(AuditService::class)->record('Product', 2, 'created', null, ['name' => 'T2'], 'T2', $adminT2->user_id, 202);

        // Request 1 as Tenant 101
        $res1 = $this->asUser($adminT1)->getJson('/api/admin/audits')->assertOk();
        $ids1 = collect($res1->json('data'))->pluck('audit_id')->all();
        $this->assertContains($audit1->audit_id, $ids1);
        $this->assertNotContains($audit2->audit_id, $ids1);

        // Request 2 immediately as Tenant 202 (no stale context from 101)
        $res2 = $this->asUser($adminT2)->getJson('/api/admin/audits')->assertOk();
        $ids2 = collect($res2->json('data'))->pluck('audit_id')->all();
        $this->assertContains($audit2->audit_id, $ids2);
        $this->assertNotContains($audit1->audit_id, $ids2);
    }

    public function test_authenticated_user_without_tenant_must_return_403_once_c106_is_merged(): void
    {
        $userWithoutTenant = User::factory()->create(['tenant_id' => null]);

        $response = $this->asUser($userWithoutTenant)->getJson('/api/orders');

        if ($response->getStatusCode() !== 403) {
            $this->markTestIncomplete(
                'Gate pendiente de C-106 (PR #12): usuario autenticado con tenant_id=null recibió HTTP '
                . $response->getStatusCode()
                . ' en GET /api/orders; debe retornar 403 fail-closed tras integrar C-106.'
            );
        }

        $response->assertForbidden();
    }
}
