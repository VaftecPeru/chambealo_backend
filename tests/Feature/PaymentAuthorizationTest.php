<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function asJwt(User $user): static
    {
        $token = auth('api')->login($user);

        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_user_cannot_start_payment_for_another_users_order(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $order = Order::create([
            'order_id' => 'ORD-PAY-' . uniqid(),
            'tenant_id' => 1,
            'user_id' => $owner->user_id,
            'total_amount' => 100,
            'taxes' => 0,
            'shipping_cost' => 0,
            'status' => Order::STATUS_CHECKOUT,
            'items' => [],
            'shipping_address' => [],
            'billing_address' => [],
            'discount' => 0,
        ]);

        $this->asJwt($intruder)
            ->postJson('/api/payment/session', [
                'gateway' => 'paypal',
                'order_id' => $order->id,
            ])
            ->assertNotFound();
    }

    public function test_payment_session_requires_authentication(): void
    {
        $this->postJson('/api/payment/session', [
            'gateway' => 'paypal',
            'order_id' => 1,
        ])->assertUnauthorized();
    }
}
