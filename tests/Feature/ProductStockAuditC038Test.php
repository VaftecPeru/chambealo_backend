<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStockAuditC038Test extends TestCase
{
    use RefreshDatabase;

    private function setupProduct(): array
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create([
            'name' => 'Inventario QA',
            'slug' => 'inventario-qa-' . uniqid(),
        ]);
        $brand = Brand::create([
            'user_id' => $admin->user_id,
            'brand_name' => 'Marca QA',
            'visibility_status' => 'visible',
        ]);
        $product = Product::create([
            'brand_id' => $brand->brand_id,
            'category_id' => $category->category_id,
            'name' => 'Producto QA',
            'slug' => 'producto-audit-' . uniqid(),
            'price' => 10,
            'stock' => 10,
            'status' => 'active',
        ]);
        $this->withHeader('Authorization', 'Bearer ' . auth('api')->login($admin));
        return [$admin, $product];
    }

    public function test_updating_product_stock_records_adjustment(): void
    {
        [$admin, $product] = $this->setupProduct();
        $this->putJson('/api/products/' . $product->product_id, ['stock' => 15])
            ->assertOk()->assertJsonPath('data.stock', 15);

        $this->assertSame(15, (int) $product->fresh()->stock);
        $movement = InventoryMovement::firstOrFail();
        $this->assertSame('adjust', $movement->type);
        $this->assertSame(10, $movement->stock_before);
        $this->assertSame(15, $movement->stock_after);
        $this->assertSame(5, $movement->quantity);
        $this->assertSame((int) $admin->user_id, (int) $movement->performed_by);
        $this->assertSame('product_update', $movement->reference_type);
    }

    public function test_price_update_and_identical_stock_do_not_create_movement(): void
    {
        [, $product] = $this->setupProduct();
        $this->putJson('/api/products/' . $product->product_id, [
            'price' => 11.50,
            'stock' => 10,
        ])->assertOk();
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_rejected_stock_update_does_not_change_stock_or_history(): void
    {
        [, $product] = $this->setupProduct();
        $this->putJson('/api/products/' . $product->product_id, ['stock' => -1])
            ->assertUnprocessable();
        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 0);
    }
}
