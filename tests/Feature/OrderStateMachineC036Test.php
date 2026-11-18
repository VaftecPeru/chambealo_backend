<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStateMachineC036Test extends TestCase
{
    use RefreshDatabase;

    private function order(User $user, string $status): Order
    {
        return Order::create([
            'order_id' => 'ORD-C036-' . uniqid(),
            'tenant_id' => 1,
            'user_id' => $user->user_id,
            'total_amount' => '50.00',
            'taxes' => 0,
            'shipping_cost' => 0,
            'status' => $status,
            'items' => [],
            'shipping_address' => [],
            'billing_address' => [],
            'discount' => 0,
        ]);
    }

    private function asJwt(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . auth('api')->login($user));
    }

    public function test_shipped_then_delivered_only_in_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order($admin, Order::STATUS_PAID);

        $this->asJwt($admin)->patchJson("/api/orders/{$order->order_id}/status", [
            'status' => 'shipped',
        ])->assertOk();
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);

        $this->asJwt($admin)->patchJson("/api/orders/{$order->order_id}/status", [
            'status' => 'delivered',
        ])->assertOk();
        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);

        $this->asJwt($admin)->patchJson("/api/orders/{$order->order_id}/status", [
            'status' => 'shipped',
        ])->assertStatus(422);
        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);
    }

    public function test_invalid_transitions_are_rejected_without_changing_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $checkout = $this->order($admin, Order::STATUS_CHECKOUT);

        $this->asJwt($admin)->patchJson("/api/orders/{$checkout->order_id}/status", [
            'status' => 'shipped',
        ])->assertStatus(422);
        $this->assertSame(Order::STATUS_CHECKOUT, $checkout->fresh()->status);

        $paid = $this->order($admin, Order::STATUS_PAID);
        $this->asJwt($admin)->patchJson("/api/orders/{$paid->order_id}/status", [
            'status' => 'delivered',
        ])->assertStatus(422);
        $this->assertSame(Order::STATUS_PAID, $paid->fresh()->status);
    }

    public function test_paid_order_cannot_be_cancelled(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user, Order::STATUS_PAID);

        $this->asJwt($user)->patchJson("/api/orders/{$order->order_id}/cancel")
            ->assertStatus(422);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_active_payment_prevents_cancellation_and_stock_release(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user, Order::STATUS_PAYMENT_PENDING);

        Payment::create([
            'order_id' => $order->order_id,
            'payment_id' => 'PAYPAL-C036-' . uniqid(),
            'gateway' => 'paypal',
            'status' => 'pending',
            'amount' => '12.50',
            'currency' => 'USD',
            'email' => $user->email,
            'user_id' => $user->user_id,
            'tenant_id' => 1,
        ]);

        $this->asJwt($user)->patchJson("/api/orders/{$order->order_id}/cancel")
            ->assertStatus(409);
        $this->assertSame(Order::STATUS_PAYMENT_PENDING, $order->fresh()->status);
    }

    public function test_model_rejects_skipping_or_reversing_states(): void
    {
        $order = new Order(['status' => Order::STATUS_CHECKOUT]);
        $this->assertTrue($order->canTransitionTo(Order::STATUS_PAYMENT_PENDING));
        $this->assertFalse($order->canTransitionTo(Order::STATUS_SHIPPED));

        $order->status = Order::STATUS_DELIVERED;
        $this->assertFalse($order->canTransitionTo(Order::STATUS_PAID));
        $this->assertFalse($order->canTransitionTo(Order::STATUS_CANCELLED));
    }
}
