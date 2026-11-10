<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryMovementsC038Test extends TestCase
{
    use RefreshDatabase;

    private function acting(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . auth('api')->login($user));
    }

    private function product(User $owner, int $stock = 10): Product
    {
        $brand = Brand::create(['user_id' => $owner->user_id, 'brand_name' => 'Marca C038', 'visibility_status' => 'visible']);
        $category = Category::create(['name' => 'Categoria C038', 'slug' => 'c038-' . uniqid()]);
        return Product::create(['brand_id' => $brand->brand_id, 'category_id' => $category->category_id,
            'name' => 'Producto C038', 'slug' => 'c038-prod-' . uniqid(), 'price' => 10, 'stock' => $stock, 'status' => 'active']);
    }

    public function test_admin_entry_exit_and_adjustment_create_audit_history(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->product($admin);
        $this->acting($admin);

        $this->postJson('/api/inventory/movements', ['product_id' => $product->product_id, 'type' => 'in', 'quantity' => 5, 'reason' => 'Reposicion'])
            ->assertCreated()->assertJsonPath('data.stock_before', 10)->assertJsonPath('data.stock_after', 15);
        $this->postJson('/api/inventory/movements', ['product_id' => $product->product_id, 'type' => 'out', 'quantity' => 3, 'reason' => 'Merma'])
            ->assertCreated()->assertJsonPath('data.stock_after', 12);
        $this->postJson('/api/inventory/movements', ['product_id' => $product->product_id, 'type' => 'adjust', 'target_stock' => 9, 'reason' => 'Conteo fisico'])
            ->assertCreated()->assertJsonPath('data.stock_after', 9);
        $this->assertSame(9, (int) $product->fresh()->stock);
        $this->getJson('/api/inventory/movements?product_id=' . $product->product_id)->assertOk()->assertJsonCount(3, 'data');
        $this->assertDatabaseCount('inventory_movements', 3);
    }

    public function test_insufficient_stock_does_not_create_movement(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->product($admin, 2);
        $this->acting($admin)->postJson('/api/inventory/movements', ['product_id' => $product->product_id, 'type' => 'out', 'quantity' => 3, 'reason' => 'Salida'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(2, (int) $product->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_vendor_cannot_change_another_owners_stock(): void
    {
        $owner = User::factory()->vendor()->create();
        $other = User::factory()->vendor()->create();
        $product = $this->product($owner);
        $this->acting($other)->postJson('/api/inventory/movements', ['product_id' => $product->product_id, 'type' => 'in', 'quantity' => 5, 'reason' => 'Reposicion'])
            ->assertForbidden();
        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_customer_cannot_access_inventory_routes(): void
    {
        $customer = User::factory()->create();
        $this->acting($customer)->getJson('/api/inventory/movements')->assertForbidden();
        $this->postJson('/api/inventory/movements', [])->assertForbidden();
    }

    public function test_adjustment_requires_target_and_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->product($admin);
        $this->acting($admin)->postJson('/api/inventory/movements', ['product_id' => $product->product_id, 'type' => 'adjust', 'reason' => 'Conteo fisico'])
            ->assertUnprocessable()->assertJsonValidationErrors('target_stock');
        $this->assertDatabaseCount('inventory_movements', 0);
    }
}
