<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockReservationTest extends TestCase
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

    private function orderPayload(array $items): array
    {
        return [
            'items' => $items,
            'shipping_address' => ['address' => 'Av. Test 123'],
            'billing_address' => ['address' => 'Av. Test 123'],
        ];
    }

    public function test_order_with_more_than_available_stock_returns_422_with_details(): void
    {
        $user = User::factory()->create();
        $product = $this->createCatalog($user);

        $this->asJwt($user)
            ->postJson('/api/orders', $this->orderPayload([
                ['product_id' => $product->product_id, 'quantity' => 11],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('product_id', $product->product_id)
            ->assertJsonPath('requested', 11)
            ->assertJsonPath('available', 10);

        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(0, Order::count());
    }

    public function test_product_with_zero_stock_cannot_be_ordered(): void
    {
        $user = User::factory()->create();
        $product = $this->createCatalog($user);
        $product->update(['stock' => 0]);

        $this->asJwt($user)
            ->postJson('/api/orders', $this->orderPayload([
                ['product_id' => $product->product_id, 'quantity' => 1],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('available', 0);

        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertSame(0, Order::count());
    }

    public function test_unknown_product_returns_422_with_product_id(): void
    {
        $user = User::factory()->create();

        $this->asJwt($user)
            ->postJson('/api/orders', $this->orderPayload([
                ['product_id' => 999999, 'quantity' => 1],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'product_unavailable')
            ->assertJsonPath('product_id', 999999);
    }

    public function test_repeated_product_lines_are_summed_into_one_item(): void
    {
        $user = User::factory()->create();
        $product = $this->createCatalog($user);

        $this->asJwt($user)
            ->postJson('/api/orders', $this->orderPayload([
                ['product_id' => $product->product_id, 'quantity' => 2],
                ['product_id' => $product->product_id, 'quantity' => 3],
            ]))
            ->assertCreated()
            ->assertJsonCount(1, 'order.items')
            ->assertJsonPath('order.items.0.quantity', 5);

        $this->assertSame(5, (int) $product->fresh()->stock);
    }

    public function test_repeated_lines_cannot_bypass_stock_check(): void
    {
        $user = User::factory()->create();
        $product = $this->createCatalog($user);

        $this->asJwt($user)
            ->postJson('/api/orders', $this->orderPayload([
                ['product_id' => $product->product_id, 'quantity' => 6],
                ['product_id' => $product->product_id, 'quantity' => 6],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('requested', 12)
            ->assertJsonPath('available', 10);

        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_failed_order_does_not_keep_stock_reserved_for_other_products(): void
    {
        $user = User::factory()->create();
        $first = $this->createCatalog($user);
        $second = $this->createCatalog($user);
        $second->update(['stock' => 1]);

        $this->asJwt($user)
            ->postJson('/api/orders', $this->orderPayload([
                ['product_id' => $first->product_id, 'quantity' => 2],
                ['product_id' => $second->product_id, 'quantity' => 5],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_stock');

        $this->assertSame(10, (int) $first->fresh()->stock);
        $this->assertSame(1, (int) $second->fresh()->stock);
        $this->assertSame(0, Order::count());
    }

    public function test_cancelling_twice_does_not_restore_stock_twice(): void
    {
        $user = User::factory()->create();
        $product = $this->createCatalog($user);

        $created = $this->asJwt($user)->postJson('/api/orders', $this->orderPayload([
            ['product_id' => $product->product_id, 'quantity' => 3],
        ]));

        $orderId = $created->json('order.order_id');
        $this->assertSame(7, (int) $product->fresh()->stock);

        $this->asJwt($user)->patchJson("/api/orders/{$orderId}/cancel")->assertOk();
        $this->assertSame(10, (int) $product->fresh()->stock);

        $this->asJwt($user)->patchJson("/api/orders/{$orderId}/cancel")->assertStatus(422);
        $this->assertSame(10, (int) $product->fresh()->stock);
    }
}