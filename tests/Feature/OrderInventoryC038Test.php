<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderInventoryC038Test extends TestCase
{
    use RefreshDatabase;

    private function asJwt(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . auth('api')->login($user));
    }

    private function product(User $owner): Product
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Categoria C038', 'slug' => 'categoria-c038-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ], 'category_id');
        $brandId = DB::table('brands')->insertGetId([
            'user_id' => $owner->user_id, 'brand_name' => 'Marca C038',
            'visibility_status' => 'visible', 'created_at' => now(), 'updated_at' => now(),
        ], 'brand_id');
        return Product::create([
            'brand_id' => $brandId, 'category_id' => $categoryId,
            'name' => 'Producto C038', 'slug' => 'producto-c038-' . uniqid(),
            'price' => 20, 'stock' => 10, 'status' => 'active',
        ]);
    }

    private function createOrder(User $user, array $items)
    {
        return $this->asJwt($user)->postJson('/api/orders', [
            'items' => $items,
            'shipping_address' => ['address' => 'Direccion de prueba'],
            'billing_address' => ['address' => 'Direccion de prueba'],
        ]);
    }

    public function test_order_and_cancellation_have_exact_stock_history(): void
    {
        $user = User::factory()->create();
        $product = $this->product($user);
        $created = $this->createOrder($user, [
            ['product_id' => $product->product_id, 'quantity' => 3],
        ])->assertCreated();
        $orderId = $created->json('order.order_id');
        $this->assertSame(7, (int) $product->fresh()->stock);
        $out = InventoryMovement::where('reference_type', 'order')->where('reference_id', $orderId)->firstOrFail();
        $this->assertSame('out', $out->type);
        $this->assertSame(10, $out->stock_before);
        $this->assertSame(7, $out->stock_after);
        $this->assertSame(3, $out->quantity);
        $this->assertSame((int) $user->user_id, (int) $out->performed_by);

        $this->asJwt($user)->patchJson("/api/orders/{$orderId}/cancel")->assertOk();
        $this->assertSame(10, (int) $product->fresh()->stock);
        $entries = InventoryMovement::where('reference_type', 'order')
            ->where('reference_id', $orderId)->orderBy('movement_id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('in', $entries[1]->type);
        $this->assertSame(7, $entries[1]->stock_before);
        $this->assertSame(10, $entries[1]->stock_after);

        $this->asJwt($user)->patchJson("/api/orders/{$orderId}/cancel")->assertStatus(422);
        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(2, InventoryMovement::where('reference_id', $orderId)->count());
    }

    public function test_rejected_order_does_not_create_movements(): void
    {
        $user = User::factory()->create();
        $product = $this->product($user);
        $this->createOrder($user, [
            ['product_id' => $product->product_id, 'quantity' => 11],
        ])->assertStatus(422);
        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_order_with_repeated_items_creates_one_movement(): void
    {
        $user = User::factory()->create();
        $product = $this->product($user);
        $created = $this->createOrder($user, [
            ['product_id' => $product->product_id, 'quantity' => 2],
            ['product_id' => $product->product_id, 'quantity' => 3],
        ])->assertCreated();
        $moves = InventoryMovement::where('reference_id', $created->json('order.order_id'))->get();
        $this->assertCount(1, $moves);
        $this->assertSame(5, $moves[0]->quantity);
        $this->assertSame(5, $moves[0]->stock_after);
        $this->assertSame(5, (int) $product->fresh()->stock);
    }
}
