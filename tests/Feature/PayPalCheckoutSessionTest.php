<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayPalCheckoutSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(
            \Illuminate\Routing\Middleware\ThrottleRequests::class
        );

        Cache::forget('paypal_fx_usd_pen');

        config()->set('payment.default_currency', 'PEN');
        config()->set('payment.paypal_fx.max_age_minutes', 1440);
    }


        protected function tearDown(): void
        {
            Cache::forget('paypal_fx_usd_pen');

            parent::tearDown();
        }

    private function createOrder(User $user): Order
    {
        return Order::create([
            'order_id' => 'ORD-CHECKOUT-' . uniqid(),
            'tenant_id' => 1,
            'user_id' => $user->user_id,
            'total_amount' => '100.00',
            'taxes' => 0,
            'shipping_cost' => 0,
            'status' => Order::STATUS_CHECKOUT,
            'items' => [],
            'shipping_address' => [],
            'billing_address' => [],
            'discount' => 0,
        ]);
    }

    private function authenticate(User $user): void
    {
        $token = auth('api')->login($user);

        $this->withHeader(
            'Authorization',
            'Bearer ' . $token
        );
    }

    private function configureExchangeRate(): void
    {
        config()->set('payment.default_currency', 'PEN');
        config()->set('payment.paypal_fx.max_age_minutes', 1440);

        Cache::forget('paypal_fx_usd_pen');
    }

    private function fakeExchangeRate($rate = 3.50): void
    {
        Http::fake([
            'https://open.er-api.com/v6/latest/USD' => Http::response([
                'result' => 'success',
                'rates' => [
                    'PEN' => $rate,
                ],
                'time_last_update_utc' => now()->toRfc7231String(),
            ], 200),
        ]);
    }

    private function fakePayPal(
        string $orderId,
        int $status = 201
    ): void {
        Http::fake([
            '*paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'TOKEN_TEST',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),

            '*paypal.com/v2/checkout/orders' => Http::response([
                'id' => $orderId,
                'status' => 'CREATED',
                'links' => [[
                    'rel' => 'approve',
                    'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=TEST',
                ]],
            ], $status),
        ]);
    }

    public function test_checkout_creates_paypal_session_in_usd(): void
    {
        $this->configureExchangeRate();

        config()->set('payment.paypal.client_id', 'CLIENT_TEST');
        config()->set('payment.paypal.client_secret', 'SECRET_TEST');

        Http::preventStrayRequests();

        $this->fakeExchangeRate();
        $this->fakePayPal('PAYPAL-CHECKOUT-TEST');

        $user = User::factory()->create();
        $order = $this->createOrder($user);

        $this->authenticate($user);

        $response = $this->postJson(
            '/api/payment/session',
            [
                'gateway' => 'paypal',
                'order_id' => $order->id,
            ]
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', '28.57')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.original_amount', '100')
            ->assertJsonPath('data.original_currency', 'PEN')
            ->assertJsonPath(
                'data.gateway_id',
                'PAYPAL-CHECKOUT-TEST'
            );

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->order_id,
            'gateway' => 'paypal',
            'status' => 'pending',
            'payment_id' => 'PAYPAL-CHECKOUT-TEST',
            'amount' => '28.57',
            'currency' => 'USD',
            'original_amount' => '100.00',
            'original_currency' => 'PEN',
        ]);

        $this->assertSame(
            Order::STATUS_PAYMENT_PENDING,
            $order->fresh()->status
        );

        Http::assertSentCount(3);
    }

    public function test_checkout_rejects_missing_exchange_rate(): void
    {
        $this->configureExchangeRate();

        Http::preventStrayRequests();

        Http::fake([
            'https://open.er-api.com/v6/latest/USD' => Http::response([
                'result' => 'success',
                'rates' => [],
                'time_last_update_utc' => now()->toRfc7231String(),
            ], 200),
        ]);

        $user = User::factory()->create();
        $order = $this->createOrder($user);

        $this->authenticate($user);

        $this->postJson('/api/payment/session', [
            'gateway' => 'paypal',
            'order_id' => $order->id,
        ])
            ->assertStatus(503)
            ->assertJsonPath('success', false);

        Http::assertSentCount(1);

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'paypal.com');
        });

        $this->assertDatabaseMissing('payments', [
            'order_id' => $order->order_id,
        ]);
    }

    public function test_checkout_does_not_create_duplicate_paypal_order(): void
    {
        $this->configureExchangeRate();

        config()->set('payment.paypal.client_id', 'CLIENT_TEST');
        config()->set('payment.paypal.client_secret', 'SECRET_TEST');

        Http::preventStrayRequests();

        $this->fakeExchangeRate();
        $this->fakePayPal('PAYPAL-CHECKOUT-ONCE');

        $user = User::factory()->create();
        $order = $this->createOrder($user);

        $this->authenticate($user);

        $payload = [
            'gateway' => 'paypal',
            'order_id' => $order->id,
        ];

        $this->postJson('/api/payment/session', $payload)
            ->assertOk();

        $this->postJson('/api/payment/session', $payload)
            ->assertStatus(409);

        $this->assertSame(
            1,
            Payment::where('order_id', $order->order_id)->count()
        );

        Http::assertSentCount(3);
    }

    public function test_connection_failure_preserves_uncertain_reservation(): void
    {
        $this->configureExchangeRate();

        config()->set('payment.paypal.client_id', 'CLIENT_TEST');
        config()->set('payment.paypal.client_secret', 'SECRET_TEST');

        Http::preventStrayRequests();

        $this->fakeExchangeRate();

        Http::fake([
            '*paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'TOKEN_TEST',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),

            '*paypal.com/v2/checkout/orders' => Http::response(
                ['message' => 'Simulated failure'],
                503
            ),
        ]);

        $user = User::factory()->create();
        $order = $this->createOrder($user);

        $this->authenticate($user);

        $this->postJson('/api/payment/session', [
            'gateway' => 'paypal',
            'order_id' => $order->id,
        ])
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->order_id,
            'gateway' => 'paypal',
            'status' => 'creation_uncertain',
            'payment_id' => null,
        ]);

        $this->assertSame(
            Order::STATUS_CHECKOUT,
            $order->fresh()->status
        );
    }
}
