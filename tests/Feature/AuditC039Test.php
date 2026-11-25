<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditC039Test extends TestCase
{
    use RefreshDatabase;

    private function acting(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . auth('api')->login($user));
    }

    public function test_product_crud_and_image_upload_generate_audit_records(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['tenant_id' => 1]);
        $brand = Brand::create([
            'user_id' => $admin->user_id,
            'brand_name' => 'Marca Audit',
            'visibility_status' => 'visible',
        ]);
        $category = Category::create([
            'name' => 'Categoria Audit',
            'slug' => 'cat-audit-' . uniqid(),
        ]);

        // Create
        $createRes = $this->acting($admin)->postJson('/api/products', [
            'brand_id' => $brand->brand_id,
            'category_id' => $category->category_id,
            'name' => 'Taladro Industrial',
            'slug' => 'taladro-industrial-' . uniqid(),
            'description' => 'Taladro de alto torque',
            'price' => 250.00,
            'stock' => 15,
            'status' => 'active',
        ])->assertCreated();

        $productId = (int) $createRes->json('data.product_id');

        $this->assertDatabaseHas('audits', [
            'entity_type' => 'Product',
            'entity_id' => $productId,
            'action' => 'created',
            'performed_by' => $admin->user_id,
            'tenant_id' => 1,
        ]);

        $createdAudit = Audit::forEntity('Product', $productId)->action('created')->firstOrFail();
        $this->assertNull($createdAudit->old_values);
        $this->assertSame('Taladro Industrial', $createdAudit->new_values['name']);

        // Update
        $this->acting($admin)->putJson("/api/products/{$productId}", [
            'name' => 'Taladro Industrial Pro',
            'price' => 299.50,
            'stock' => 20,
        ])->assertOk();

        $updatedAudit = Audit::forEntity('Product', $productId)->action('updated')->firstOrFail();
        $this->assertSame('Taladro Industrial', $updatedAudit->old_values['name']);
        $this->assertSame('Taladro Industrial Pro', $updatedAudit->new_values['name']);
        $this->assertSame(15, (int) $updatedAudit->old_values['stock']);
        $this->assertSame(20, (int) $updatedAudit->new_values['stock']);
        $this->assertSame(1, $updatedAudit->tenant_id);

        // Upload image (minimal valid 1x1 PNG without requiring GD extension)
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $this->acting($admin)->post("/api/products/{$productId}/image", [
            'image' => UploadedFile::fake()->createWithContent('taladro.png', $pngBytes),
        ])->assertCreated();

        $this->assertDatabaseHas('audits', [
            'entity_type' => 'Product',
            'entity_id' => $productId,
            'action' => 'image_uploaded',
            'performed_by' => $admin->user_id,
        ]);

        // Delete
        $this->acting($admin)->deleteJson("/api/products/{$productId}")->assertOk();

        $deletedAudit = Audit::forEntity('Product', $productId)->action('deleted')->firstOrFail();
        $this->assertSame('Taladro Industrial Pro', $deletedAudit->old_values['name']);
        $this->assertNull($deletedAudit->new_values);
    }

    public function test_category_crud_generates_audit_records(): void
    {
        $admin = User::factory()->admin()->create(['tenant_id' => 2]);

        $createRes = $this->acting($admin)->postJson('/api/categories', [
            'name' => 'Herramientas Eléctricas',
            'slug' => 'herramientas-electricas-' . uniqid(),
            'description' => 'Categoría inicial',
        ])->assertCreated();

        $categoryId = (int) $createRes->json('data.category_id');

        $this->assertDatabaseHas('audits', [
            'entity_type' => 'Category',
            'entity_id' => $categoryId,
            'action' => 'created',
            'performed_by' => $admin->user_id,
            'tenant_id' => 2,
        ]);

        $this->acting($admin)->putJson("/api/categories/{$categoryId}", [
            'name' => 'Herramientas Pro',
        ])->assertOk();

        $updatedAudit = Audit::forEntity('Category', $categoryId)->action('updated')->firstOrFail();
        $this->assertSame('Herramientas Eléctricas', $updatedAudit->old_values['name']);
        $this->assertSame('Herramientas Pro', $updatedAudit->new_values['name']);

        $this->acting($admin)->deleteJson("/api/categories/{$categoryId}")->assertOk();

        $deletedAudit = Audit::forEntity('Category', $categoryId)->action('deleted')->firstOrFail();
        $this->assertSame('Herramientas Pro', $deletedAudit->old_values['name']);
        $this->assertNull($deletedAudit->new_values);
    }

    public function test_order_creation_cancellation_and_status_update_generate_audit_records(): void
    {
        $customer = User::factory()->create(['tenant_id' => 3]);
        $admin = User::factory()->admin()->create(['tenant_id' => 3]);
        $brand = Brand::create([
            'user_id' => $admin->user_id,
            'brand_name' => 'Marca Pedido',
            'visibility_status' => 'visible',
        ]);
        $category = Category::create([
            'name' => 'Cat Pedido',
            'slug' => 'cat-pedido-' . uniqid(),
        ]);
        $product = Product::create([
            'brand_id' => $brand->brand_id,
            'category_id' => $category->category_id,
            'name' => 'Sierra Circular',
            'slug' => 'sierra-circular-' . uniqid(),
            'price' => 120.00,
            'stock' => 10,
            'status' => 'active',
        ]);

        // Create order
        $orderRes = $this->acting($customer)->postJson('/api/orders', [
            'items' => [
                ['product_id' => $product->product_id, 'quantity' => 2],
            ],
            'shipping_address' => ['city' => 'Lima', 'street' => 'Av. Arequipa 100'],
            'billing_address' => ['city' => 'Lima', 'street' => 'Av. Arequipa 100'],
        ])->assertCreated();

        $orderId = $orderRes->json('order.order_id');
        $orderDbId = (int) $orderRes->json('order.id');

        $createdAudit = Audit::forEntity('Order', $orderDbId)->action('created')->firstOrFail();
        $this->assertSame($customer->user_id, $createdAudit->performed_by);
        $this->assertSame(3, $createdAudit->tenant_id);
        $this->assertSame(Order::STATUS_CHECKOUT, $createdAudit->new_values['status']);

        // Cancel order
        $this->acting($customer)->patchJson("/api/orders/{$orderId}/cancel")->assertOk();

        $cancelledAudit = Audit::forEntity('Order', $orderDbId)->action('cancelled')->firstOrFail();
        $this->assertSame(Order::STATUS_CHECKOUT, $cancelledAudit->old_values['status']);
        $this->assertSame(Order::STATUS_CANCELLED, $cancelledAudit->new_values['status']);

        // Create second paid order and update status to shipped
        $paidOrder = Order::create([
            'order_id' => 'ORD-PAID-' . uniqid(),
            'tenant_id' => 3,
            'user_id' => $customer->user_id,
            'total_amount' => 120.00,
            'taxes' => 0,
            'shipping_cost' => 0,
            'status' => Order::STATUS_PAID,
            'items' => [['product_id' => $product->product_id, 'quantity' => 1, 'price' => 120.00]],
            'shipping_address' => ['city' => 'Lima'],
            'billing_address' => ['city' => 'Lima'],
            'discount' => 0,
        ]);

        $this->acting($admin)->patchJson("/api/orders/{$paidOrder->order_id}/status", [
            'status' => Order::STATUS_SHIPPED,
        ])->assertOk();

        $statusAudit = Audit::forEntity('Order', $paidOrder->id)->action('status_updated')->firstOrFail();
        $this->assertSame($admin->user_id, $statusAudit->performed_by);
        $this->assertSame(Order::STATUS_PAID, $statusAudit->old_values['status']);
        $this->assertSame(Order::STATUS_SHIPPED, $statusAudit->new_values['status']);
    }

    public function test_admin_audits_endpoint_enforces_admin_role_and_supports_filters_and_pagination(): void
    {
        $admin = User::factory()->admin()->create(['tenant_id' => 10]);
        $vendor = User::factory()->vendor()->create(['tenant_id' => 10]);
        $customer = User::factory()->create(['tenant_id' => 10]);

        $this->getJson('/api/admin/audits')->assertUnauthorized();
        $this->acting($customer)->getJson('/api/admin/audits')->assertForbidden();
        $this->acting($vendor)->getJson('/api/admin/audits')->assertForbidden();

        $service = app(AuditService::class);
        $service->record('Product', 101, 'created', null, ['name' => 'Prod A'], 'Producto A creado', $admin->user_id, 10);
        $service->record('Product', 101, 'updated', ['name' => 'Prod A'], ['name' => 'Prod B'], 'Producto A actualizado', $vendor->user_id, 10);
        $service->record('Category', 202, 'deleted', ['name' => 'Cat X'], null, 'Categoría X eliminada', $admin->user_id, 10);

        // Pagination
        $this->acting($admin)->getJson('/api/admin/audits?per_page=2')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 2);

        // Filter by entity_type + entity_id + action
        $this->acting($admin)->getJson('/api/admin/audits?entity_type=Product&entity_id=101&action=updated')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'updated')
            ->assertJsonPath('data.0.performed_by', $vendor->user_id);

        // Filter by performed_by
        $this->acting($admin)->getJson('/api/admin/audits?performed_by=' . $admin->user_id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Filter by search
        $this->acting($admin)->getJson('/api/admin/audits?search=Categoría')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entity_type', 'Category');
    }

    public function test_admin_audits_enforces_tenant_isolation(): void
    {
        $adminTenant1 = User::factory()->admin()->create(['tenant_id' => 1]);
        $adminTenant2 = User::factory()->admin()->create(['tenant_id' => 2]);

        $service = app(AuditService::class);
        $auditT1 = $service->record('Product', 1, 'created', null, ['name' => 'T1'], 'Tenant 1', $adminTenant1->user_id, 1);
        $auditT2 = $service->record('Product', 2, 'created', null, ['name' => 'T2'], 'Tenant 2', $adminTenant2->user_id, 2);

        $this->acting($adminTenant1)->getJson('/api/admin/audits')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.audit_id', $auditT1->audit_id);

        $this->acting($adminTenant1)->getJson('/api/admin/audits/' . $auditT2->audit_id)
            ->assertNotFound();

        $this->acting($adminTenant2)->getJson('/api/admin/audits/' . $auditT2->audit_id)
            ->assertOk()
            ->assertJsonPath('data.audit_id', $auditT2->audit_id);
    }

    public function test_audit_service_and_controller_redact_sensitive_information(): void
    {
        $admin = User::factory()->admin()->create(['tenant_id' => 5]);
        $service = app(AuditService::class);

        $audit = $service->record(
            entityType: 'Order',
            entityId: 99,
            action: 'updated',
            oldValues: [
                'password' => 'SuperSecret123',
                'password_hash' => '$2y$10$abcdef',
                'payment' => [
                    'card_number' => '4111111111111111',
                    'cvv' => '123',
                    'api_key' => 'sk_live_secret_key',
                    'amount' => 150.00,
                ],
                'header' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxIn0.signature123',
            ],
            newValues: [
                'refresh_token' => 'rt_123456',
                'status' => 'paid',
            ],
            description: 'Actualización con datos sensibles',
            performedBy: $admin->user_id,
            tenantId: 5,
        );

        $this->assertNotNull($audit);
        $this->assertSame(AuditService::REDACTED_VALUE, $audit->old_values['password']);
        $this->assertSame(AuditService::REDACTED_VALUE, $audit->old_values['password_hash']);
        $this->assertSame(AuditService::REDACTED_VALUE, $audit->old_values['payment']['card_number']);
        $this->assertSame(AuditService::REDACTED_VALUE, $audit->old_values['payment']['cvv']);
        $this->assertSame(AuditService::REDACTED_VALUE, $audit->old_values['payment']['api_key']);
        $this->assertSame(150, (int) $audit->old_values['payment']['amount']);
        $this->assertSame(AuditService::REDACTED_VALUE, $audit->old_values['header']);
        $this->assertSame(AuditService::REDACTED_VALUE, $audit->new_values['refresh_token']);
        $this->assertSame('paid', $audit->new_values['status']);

        $response = $this->acting($admin)->getJson('/api/admin/audits/' . $audit->audit_id)
            ->assertOk()
            ->assertJsonPath('data.old_values.password', AuditService::REDACTED_VALUE)
            ->assertJsonPath('data.old_values.payment.cvv', AuditService::REDACTED_VALUE)
            ->assertJsonPath('data.new_values.refresh_token', AuditService::REDACTED_VALUE);

        $this->assertArrayNotHasKey('password_hash', $response->json('data.performer'));
    }
}
