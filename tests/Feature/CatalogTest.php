<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(
        string $name,
        string $status = 'active',
        int $stock = 10,
        ?int $categoryId = null,
        ?int $brandId = null
    ): Product {
        $owner = User::factory()->create();

        $categoryId ??= DB::table('categories')->insertGetId([
            'name' => 'Categoria QA ' . uniqid(),
            'slug' => 'categoria-qa-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'category_id');

        $brandId ??= DB::table('brands')->insertGetId([
            'user_id' => $owner->user_id,
            'brand_name' => 'Marca QA ' . uniqid(),
            'visibility_status' => 'visible',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'brand_id');

        return Product::create([
            'brand_id' => $brandId,
            'category_id' => $categoryId,
            'name' => $name,
            'slug' => 'producto-qa-' . uniqid(),
            'description' => 'Descripcion de prueba',
            'price' => 25.50,
            'stock' => $stock,
            'status' => $status,
        ]);
    }

    public function test_only_active_products_are_listed(): void
    {
        $this->createProduct('Producto Activo');
        $this->createProduct('Producto Inactivo', 'inactive');

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.name', 'Producto Activo');
    }

    public function test_search_products_by_name(): void
    {
        $this->createProduct('Leche Entera');
        $this->createProduct('Monitor Gaming');

        $this->getJson('/api/products?q=Leche')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('products.0.name', 'Leche Entera');
    }

    public function test_filter_products_by_category_and_brand(): void
    {
        $first = $this->createProduct('Producto Categoria A');
        $second = $this->createProduct('Producto Categoria B');

        $this->getJson(
            '/api/products?category_id=' . $first->category_id
        )
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('products.0.id', $first->product_id);

        $this->getJson(
            '/api/products?brand_id=' . $second->brand_id
        )
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('products.0.id', $second->product_id);
    }

    public function test_filter_products_with_stock(): void
    {
        $this->createProduct('Con Stock', 'active', 10);
        $this->createProduct('Sin Stock', 'active', 0);

        $this->getJson('/api/products?in_stock=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('products.0.name', 'Con Stock');
    }

    public function test_catalog_pagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createProduct('Producto ' . $i);
        }

        $this->getJson('/api/products?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(2, 'products');
    }

    public function test_product_detail_and_missing_product(): void
    {
        $product = $this->createProduct('Producto Detalle');

        $this->getJson(
            '/api/products/' . $product->product_id
        )
            ->assertOk()
            ->assertJsonPath('data.name', 'Producto Detalle')
            ->assertJsonPath('data.stock', 10);

        $this->getJson('/api/products/999999')
            ->assertNotFound();
    }

    public function test_categories_and_brands_can_be_listed(): void
    {
        $this->createProduct('Producto Relaciones');

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/brands')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_product_returns_image_url(): void
    {
        $product = $this->createProduct('Producto Imagen');
        $path = '/images/products/producto-qa.webp';

        DB::table('media')->insert([
            'related_type' => $product->getMorphClass(),
            'related_id' => $product->product_id,
            'file_url' => $path,
            'file_type' => 'image/webp',
            'uploaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson(
            '/api/products/' . $product->product_id
        );

        $response->assertOk()
            ->assertJsonPath('data.image_url', url($path))
            ->assertJsonPath('data.img1', url($path));
    }
}
