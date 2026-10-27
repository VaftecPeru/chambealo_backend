<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogStockTest extends TestCase
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

    private function stockFrom($response): ?int
    {
        $json = $response->json();
        $value = data_get($json, 'data.stock')
            ?? data_get($json, 'product.stock')
            ?? data_get($json, 'stock');

        return $value === null ? null : (int) $value;
    }

    public function test_product_detail_returns_content_with_real_stock(): void
    {
        $user = User::factory()->create();
        $product = $this->createCatalog($user);

        $response = $this->getJson("/api/products/{$product->product_id}");

        $response->assertOk();
        $this->assertNotEmpty($response->getContent());
        $this->assertSame(10, $this->stockFrom($response));
    }

    public function test_product_list_returns_content(): void
    {
        $user = User::factory()->create();
        $this->createCatalog($user);

        $response = $this->getJson('/api/products');

        $response->assertOk();
        $this->assertStringContainsString('Producto QA', $response->getContent());
    }

    public function test_catalog_stock_follows_order_and_cancellation(): void
    {
        $user = User::factory()->create();
        $product = $this->createCatalog($user);
        $url = "/api/products/{$product->product_id}";

        $this->assertSame(10, $this->stockFrom($this->getJson($url)));

        $created = $this->asJwt($user)->postJson('/api/orders', [
            'items' => [['product_id' => $product->product_id, 'quantity' => 3]],
            'shipping_address' => ['address' => 'Av. Test 123'],
            'billing_address' => ['address' => 'Av. Test 123'],
        ]);
        $created->assertCreated();

        $this->assertSame(7, $this->stockFrom($this->getJson($url)));

        $orderId = $created->json('order.order_id');
        $this->asJwt($user)->patchJson("/api/orders/{$orderId}/cancel")->assertOk();

        $this->assertSame(10, $this->stockFrom($this->getJson($url)));
    }
}