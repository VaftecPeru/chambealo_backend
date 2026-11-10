<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCrudC037Test extends TestCase
{
    use RefreshDatabase;

    private function asJwt(User $user): static
    {
        $token = auth('api')->login($user);
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function category(string $name = 'General', ?int $parentId = null): Category
    {
        return Category::create([
            'name' => $name,
            'slug' => strtolower($name) . '-' . uniqid(),
            'parent_id' => $parentId,
        ]);
    }

    private function brand(User $owner): Brand
    {
        return Brand::create([
            'user_id' => $owner->user_id,
            'brand_name' => 'Marca de prueba',
            'visibility_status' => 'visible',
        ]);
    }

    private function payload(Brand $brand, Category $category): array
    {
        return [
            'brand_id' => $brand->brand_id,
            'category_id' => $category->category_id,
            'name' => 'Producto de prueba',
            'price' => 25.50,
            'stock' => 10,
            'status' => 'active',
        ];
    }

    public function test_vendor_can_create_read_update_delete_and_list_own_product(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $category = $this->category();
        $brand = $this->brand($vendor);

        $created = $this->asJwt($vendor)->postJson('/api/products', $this->payload($brand, $category));
        $created->assertCreated()->assertJsonPath('success', true);
        $id = $created->json('data.product_id');

        $this->getJson("/api/products/{$id}")->assertOk();
        $this->getJson('/api/my-products')->assertOk()->assertJsonCount(1, 'data');

        $this->putJson("/api/products/{$id}", ['price' => 35.00])
            ->assertOk()->assertJsonPath('data.price', 35);
        $this->assertSame('35.00', Product::findOrFail($id)->price);

        $this->deleteJson("/api/products/{$id}")->assertOk();
        $this->assertSoftDeleted('products', ['product_id' => $id]);
        $this->getJson('/api/my-products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_vendor_cannot_create_product_for_foreign_brand(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $other = User::factory()->create(['role' => 'vendor']);
        $brand = $this->brand($owner);
        $category = $this->category();

        $this->asJwt($other)->postJson('/api/products', $this->payload($brand, $category))
            ->assertForbidden();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_vendor_cannot_modify_or_delete_foreign_product(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $other = User::factory()->create(['role' => 'vendor']);
        $brand = $this->brand($owner);
        $category = $this->category();
        $product = Product::create($this->payload($brand, $category) + ['slug' => 'foreign-' . uniqid()]);

        $this->asJwt($other)->putJson("/api/products/{$product->product_id}", ['price' => 100])
            ->assertForbidden();
        $this->deleteJson("/api/products/{$product->product_id}")->assertForbidden();
        $this->assertSame('25.50', $product->fresh()->price);
        $this->assertNull($product->fresh()->deleted_at);
    }

    public function test_vendor_cannot_move_product_to_foreign_brand(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $other = User::factory()->create(['role' => 'vendor']);
        $ownBrand = $this->brand($owner);
        $foreignBrand = $this->brand($other);
        $category = $this->category();
        $product = Product::create($this->payload($ownBrand, $category) + ['slug' => 'mine-' . uniqid()]);

        $this->asJwt($owner)->putJson("/api/products/{$product->product_id}", [
            'brand_id' => $foreignBrand->brand_id,
        ])->assertForbidden();
        $this->assertSame((int) $ownBrand->brand_id, (int) $product->fresh()->brand_id);
    }

    public function test_admin_can_manage_products_from_any_brand(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $brand = $this->brand($vendor);
        $category = $this->category();

        $this->asJwt($admin)->postJson('/api/products', $this->payload($brand, $category))
            ->assertCreated();
    }

    public function test_only_admin_can_create_categories(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $this->asJwt($vendor)->postJson('/api/categories', ['name' => 'Privada'])
            ->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->asJwt($admin)->postJson('/api/categories', ['name' => 'Pública'])
            ->assertCreated()->assertJsonPath('success', true);
    }

    public function test_admin_can_create_read_update_and_delete_category(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $created = $this->asJwt($admin)->postJson('/api/categories', ['name' => 'Tecnología']);
        $created->assertCreated();
        $id = $created->json('data.category_id');

        $this->getJson("/api/categories/{$id}")->assertOk();
        $this->putJson("/api/categories/{$id}", ['description' => 'Descripción modificada'])
            ->assertOk()->assertJsonPath('data.description', 'Descripción modificada');
        $this->deleteJson("/api/categories/{$id}")->assertOk();
        $this->assertSoftDeleted('categories', ['category_id' => $id]);
    }

    public function test_category_hierarchy_cannot_form_cycle(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $parent = $this->category('Raiz');
        $child = $this->category('Hija', $parent->category_id);
        $grandchild = $this->category('Nieta', $child->category_id);

        $this->asJwt($admin)->putJson("/api/categories/{$parent->category_id}", [
            'parent_id' => $grandchild->category_id,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_category_with_product_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $category = $this->category();
        $brand = $this->brand($vendor);
        Product::create($this->payload($brand, $category) + ['slug' => 'category-product-' . uniqid()]);

        $this->asJwt($admin)->deleteJson("/api/categories/{$category->category_id}")
            ->assertStatus(409);
        $this->assertNull($category->fresh()->deleted_at);
    }
}
