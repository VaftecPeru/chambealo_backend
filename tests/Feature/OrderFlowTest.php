<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private function asJwt(User $user): static
    {
        $token = auth('api')->login($user);

        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function createCatalog(User $owner): Product
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'General',
            'slug' => 'general-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'category_id');

        $brandId = DB::table('brands')->insertGetId([
            'user_id' => $owner->user_id,
            'brand_name' => 'CHAMBEALO',
            'visibility_status' => 'visible',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'brand_id');

        return Product::create([
            'brand_id' => $brandId,
            'category_id' => $categoryId,
            'name' => 'Producto QA',
            'slug' => 'producto-qa-' . uniqid(),
            'description' => 'Producto para prueba',
            'price' => 25.50,
            'stock' => 10,
            'status' => 'active',
        ]);
    }

    public function test_order_total_is_calculated_by_backend_and_stock_is_reserved(): void
    {
        $user = User::factory()->create();
        $product = $this->createCatalog($user);

        $response = $this->asJwt($user)->postJson('/api/orders', [
            'items' => [[
                'product_id' => $product->product_id,
                'quantity' => 2,
            ]],
            'shipping_address' => ['address' => 'Av. Test 123'],
            'billing_address' => ['address' => 'Av. Test 123'],
            'taxes' => 9999,
            'shipping_cost' => 9999,
            'discount' => 9999,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order.total_amount', 51)
            ->assertJsonPath('order.taxes', 0)
            ->assertJsonPath('order.shipping_cost', 0)
            ->assertJsonPath('order.discount', 0);

        $this->assertSame(8, (int) $product->fresh()->stock);
    }

    public function test_cancelling_checkout_order_restores_reserved_stock(): void
    {
        $user = User::factory()->create();
        $product = $this->createCatalog($user);

        $created = $this->asJwt($user)->postJson('/api/orders', [
            'items' => [[
                'product_id' => $product->product_id,
                'quantity' => 3,
            ]],
            'shipping_address' => ['address' => 'Av. Test 123'],
            'billing_address' => ['address' => 'Av. Test 123'],
        ]);

        $orderId = $created->json('order.order_id');
        $this->assertSame(7, (int) $product->fresh()->stock);

        $this->asJwt($user)
            ->patchJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_CANCELLED);

        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_user_cannot_view_another_users_order(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $order = Order::create([
            'order_id' => 'ORD-AUTH-' . uniqid(),
            'tenant_id' => 1,
            'user_id' => $owner->user_id,
            'total_amount' => 50,
            'taxes' => 0,
            'shipping_cost' => 0,
            'status' => Order::STATUS_CHECKOUT,
            'items' => [],
            'shipping_address' => [],
            'billing_address' => [],
            'discount' => 0,
        ]);

        $this->asJwt($intruder)
            ->getJson("/api/orders/{$order->order_id}")
            ->assertNotFound();
    }
}
