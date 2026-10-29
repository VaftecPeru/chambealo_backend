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
    public function test_insufficient_stock_does_not_create_order(): void
    {
        $user = User::factory()->create();
        $product = $this->createCatalog($user);

        $this->asJwt($user)->postJson('/api/orders', [
            'items' => [
                [
                    'product_id' => $product->product_id,
                    'quantity' => 20,
                ],
            ],
            'shipping_address' => ['address' => 'Av. Test 123'],
            'billing_address' => ['address' => 'Av. Test 123'],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_duplicate_products_are_grouped_before_reserving_stock(): void
    {
        $user = User::factory()->create();
        $product = $this->createCatalog($user);

        // Las cantidades 2 + 3 deben formar una sola línea.
        $response = $this->asJwt($user)->postJson('/api/orders', [
            'items' => [
                [
                    'product_id' => $product->product_id,
                    'quantity' => 2,
                ],
                [
                    'product_id' => $product->product_id,
                    'quantity' => 3,
                ],
            ],
            'shipping_address' => ['address' => 'Av. Test 123'],
            'billing_address' => ['address' => 'Av. Test 123'],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('order.total_amount', 127.5)
            ->assertJsonPath('order.items.0.quantity', 5)
            ->assertJsonCount(1, 'order.items');

        $this->assertSame(5, (int) $product->fresh()->stock);

        // Hay 5 disponibles; pedir 3 + 3 debe fallar.
        $this->asJwt($user)->postJson('/api/orders', [
            'items' => [
                [
                    'product_id' => $product->product_id,
                    'quantity' => 3,
                ],
                [
                    'product_id' => $product->product_id,
                    'quantity' => 3,
                ],
            ],
            'shipping_address' => ['address' => 'Av. Test 123'],
            'billing_address' => ['address' => 'Av. Test 123'],
        ])->assertUnprocessable();

        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_cancelling_order_twice_does_not_restore_stock_twice(): void
    {
        $user = User::factory()->create();
        $product = $this->createCatalog($user);

        $created = $this->asJwt($user)->postJson('/api/orders', [
            'items' => [
                [
                    'product_id' => $product->product_id,
                    'quantity' => 3,
                ],
            ],
            'shipping_address' => ['address' => 'Av. Test 123'],
            'billing_address' => ['address' => 'Av. Test 123'],
        ])->assertCreated();

        $orderId = $created->json('order.order_id');

        $this->assertSame(7, (int) $product->fresh()->stock);

        $this->asJwt($user)
            ->patchJson("/api/orders/{$orderId}/cancel")
            ->assertOk();

        $this->assertSame(10, (int) $product->fresh()->stock);

        // La segunda cancelación no debe devolver más stock.
        $this->asJwt($user)
            ->patchJson("/api/orders/{$orderId}/cancel")
            ->assertUnprocessable();

        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_regular_user_cannot_update_order_status(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        $product = $this->createCatalog($user);

        $created = $this->asJwt($user)->postJson('/api/orders', [
            'items' => [
                [
                    'product_id' => $product->product_id,
                    'quantity' => 1,
                ],
            ],
            'shipping_address' => ['address' => 'Av. Test 123'],
            'billing_address' => ['address' => 'Av. Test 123'],
        ])->assertCreated();

        $orderId = $created->json('order.order_id');

        $this->asJwt($user)
            ->patchJson("/api/orders/{$orderId}/status", [
                'status' => Order::STATUS_SHIPPED,
            ])
            ->assertForbidden();

        $this->assertSame(
            Order::STATUS_CHECKOUT,
            Order::where('order_id', $orderId)->firstOrFail()->status
        );
    }

    public function test_unpaid_order_cannot_be_shipped(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $product = $this->createCatalog($admin);

        $created = $this->asJwt($admin)->postJson('/api/orders', [
            'items' => [
                [
                    'product_id' => $product->product_id,
                    'quantity' => 1,
                ],
            ],
            'shipping_address' => ['address' => 'Av. Test 123'],
            'billing_address' => ['address' => 'Av. Test 123'],
        ])->assertCreated();

        $orderId = $created->json('order.order_id');

        $this->asJwt($admin)
            ->patchJson("/api/orders/{$orderId}/status", [
                'status' => Order::STATUS_SHIPPED,
            ])
            ->assertUnprocessable();

        $this->assertSame(
            Order::STATUS_CHECKOUT,
            Order::where('order_id', $orderId)->firstOrFail()->status
        );
    }

    public function test_order_cannot_be_delivered_before_shipping(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $product = $this->createCatalog($admin);

        $created = $this->asJwt($admin)->postJson('/api/orders', [
            'items' => [
                [
                    'product_id' => $product->product_id,
                    'quantity' => 1,
                ],
            ],
            'shipping_address' => ['address' => 'Av. Test 123'],
            'billing_address' => ['address' => 'Av. Test 123'],
        ])->assertCreated();

        $orderId = $created->json('order.order_id');

        $this->asJwt($admin)
            ->patchJson("/api/orders/{$orderId}/status", [
                'status' => Order::STATUS_DELIVERED,
            ])
            ->assertUnprocessable();

        $this->assertSame(
            Order::STATUS_CHECKOUT,
            Order::where('order_id', $orderId)->firstOrFail()->status
        );
    }

    public function test_vendor_cannot_update_unrelated_order(): void
    {
        $customer = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        $vendor = User::factory()->create([
            'role' => 'vendor',
            'status' => 'active',
        ]);

        $product = $this->createCatalog($customer);

        $created = $this->asJwt($customer)
            ->postJson('/api/orders', [
                'items' => [
                    [
                        'product_id' => $product->product_id,
                        'quantity' => 1,
                    ],
                ],
                'shipping_address' => ['address' => 'Av. Test 123'],
                'billing_address' => ['address' => 'Av. Test 123'],
            ])
            ->assertCreated();

        $orderId = $created->json('order.order_id');

        // Simular un pedido pagado para evaluar la autorización
        // independientemente de las reglas de transición.
        Order::where('order_id', $orderId)
            ->update(['status' => Order::STATUS_PAID]);

        $this->asJwt($vendor)
            ->patchJson("/api/orders/{$orderId}/status", [
                'status' => Order::STATUS_SHIPPED,
            ])
            ->assertForbidden();

        $this->assertSame(
            Order::STATUS_PAID,
            Order::where('order_id', $orderId)
                ->firstOrFail()
                ->status
        );
    }

    public function test_vendor_can_ship_and_deliver_own_order(): void
    {
        $customer = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        $vendor = User::factory()->create([
            'role' => 'vendor',
            'status' => 'active',
        ]);

        $product = $this->createCatalog($vendor);

        $created = $this->asJwt($customer)
            ->postJson('/api/orders', [
                'items' => [[
                    'product_id' => $product->product_id,
                    'quantity' => 1,
                ]],
                'shipping_address' => ['address' => 'Av. Test 123'],
                'billing_address' => ['address' => 'Av. Test 123'],
            ])
            ->assertCreated();

        $orderId = $created->json('order.order_id');

        // Simular pago confirmado para comprobar las transiciones.
        Order::where('order_id', $orderId)
            ->update(['status' => Order::STATUS_PAID]);

        $this->asJwt($vendor)
            ->patchJson("/api/orders/{$orderId}/status", [
                'status' => Order::STATUS_SHIPPED,
            ])
            ->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_SHIPPED);

        $this->asJwt($vendor)
            ->patchJson("/api/orders/{$orderId}/status", [
                'status' => Order::STATUS_DELIVERED,
            ])
            ->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_DELIVERED);

        $this->assertSame(
            Order::STATUS_DELIVERED,
            Order::where('order_id', $orderId)->firstOrFail()->status
        );
    }
}
