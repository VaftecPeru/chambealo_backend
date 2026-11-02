<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use App\Events\PaymentConfirmed;
use App\Services\PayPalService;
use Illuminate\Support\Facades\Event;
use Mockery;
use App\Services\Payments\PayPalExchangeRate;

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

public function test_pending_payment_session_returns_409(): void
{
    $user = User::factory()->create();

    $order = Order::create([
        'order_id' => 'ORD-PENDING-' . uniqid(),
        'tenant_id' => 1,
        'user_id' => $user->user_id,
        'total_amount' => 59.90,
        'taxes' => 0,
        'shipping_cost' => 0,
        'status' => Order::STATUS_PAYMENT_PENDING,
        'items' => [],
        'shipping_address' => [],
        'billing_address' => [],
        'discount' => 0,
    ]);

    Payment::create([
        'order_id' => $order->order_id,
        'payment_id' => 'PAYPAL-PENDING-123',
        'gateway' => 'paypal',
        'email' => $user->email,
        'amount' => '59.90',
        'currency' => 'USD',
        'status' => 'pending',
        'user_id' => $user->user_id,
    ]);

    // Impedir cualquier solicitud HTTP externa.
    Http::preventStrayRequests();
    Http::fake();

    // USD evita el bloqueo de PEN y permite probar el 409.
    config()->set('payment.default_currency', 'USD');

    $this->asJwt($user)
        ->postJson('/api/payment/session', [
            'gateway' => 'paypal',
            'order_id' => $order->id,
        ])
        ->assertStatus(409)
        ->assertJsonPath('success', false);

    Http::assertNothingSent();

    $this->assertDatabaseHas('payments', [
        'order_id' => $order->order_id,
        'payment_id' => 'PAYPAL-PENDING-123',
        'status' => 'pending',
    ]);
}
public function test_completed_payment_cannot_create_new_session(): void
{
    $user = User::factory()->create();

    $order = Order::create([
        'order_id' => 'ORD-PAID-' . uniqid(),
        'tenant_id' => 1,
        'user_id' => $user->user_id,
        'total_amount' => 59.90,
        'taxes' => 0,
        'shipping_cost' => 0,
        'status' => Order::STATUS_PAYMENT_PENDING,
        'items' => [],
        'shipping_address' => [],
        'billing_address' => [],
        'discount' => 0,
    ]);

    Payment::create([
        'order_id' => $order->order_id,
        'payment_id' => 'PAYPAL-COMPLETED-123',
        'gateway' => 'paypal',
        'email' => $user->email,
        'amount' => '59.90',
        'currency' => 'USD',
        'status' => 'completed',
        'user_id' => $user->user_id,
    ]);

    Http::preventStrayRequests();
    Http::fake();

    config()->set('payment.default_currency', 'USD');

    $this->asJwt($user)
        ->postJson('/api/payment/session', [
            'gateway' => 'paypal',
            'order_id' => $order->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Este pedido ya fue pagado.');

    Http::assertNothingSent();
}

public function test_valid_paypal_capture_confirms_payment(): void
{
    Event::fake([PaymentConfirmed::class]);

    $user = User::factory()->create();

    $order = Order::create([
        'order_id' => 'ORD-PAYPAL-VALID-' . uniqid(),
        'tenant_id' => 1,
        'user_id' => $user->user_id,
        'total_amount' => '59.90',
        'taxes' => 0,
        'shipping_cost' => 0,
        'status' => Order::STATUS_PAYMENT_PENDING,
        'items' => [],
        'shipping_address' => [],
        'billing_address' => [],
        'discount' => 0,
    ]);

    Payment::create([
        'order_id' => $order->order_id,
        'payment_id' => 'PAYPAL-ORDER-VALID',
        'gateway' => 'paypal',
        'status' => 'pending',
        'amount' => '59.90',
        'currency' => 'USD',
        'email' => $user->email,
        'user_id' => $user->user_id,
        'tenant_id' => 1,
    ]);

    $unit = [
        'reference_id' => $order->order_id,
        'amount' => [
            'currency_code' => 'USD',
            'value' => '59.90',
        ],
    ];

    $approved = [
        'id' => 'PAYPAL-ORDER-VALID',
        'status' => 'APPROVED',
        'purchase_units' => [$unit],
    ];

    $unit['payments'] = [
        'captures' => [[
            'id' => 'PAYPAL-CAPTURE-VALID',
            'status' => 'COMPLETED',
            'amount' => [
                'currency_code' => 'USD',
                'value' => '59.90',
            ],
        ]],
    ];

    $completed = [
        'id' => 'PAYPAL-ORDER-VALID',
        'status' => 'COMPLETED',
        'purchase_units' => [$unit],
    ];

    $paypal = Mockery::mock(PayPalService::class);

    $paypal->shouldReceive('getOrder')
        ->twice()
        ->with('PAYPAL-ORDER-VALID')
        ->andReturn($approved, $completed);

    $paypal->shouldReceive('confirmPayment')
        ->once()
        ->with('PAYPAL-ORDER-VALID')
        ->andReturn($completed);

    app()->instance(PayPalService::class, $paypal);

    $this->asJwt($user)
        ->postJson('/api/payment/confirm', [
            'gateway' => 'paypal',
            'payment_id' => 'PAYPAL-ORDER-VALID',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'completed');

    $this->assertDatabaseHas('payments', [
        'payment_id' => 'PAYPAL-ORDER-VALID',
        'status' => 'completed',
    ]);

    $this->assertDatabaseHas('transactions', [
        'transaction_id' => 'PAYPAL-CAPTURE-VALID',
        'payment_method' => 'paypal',
        'status' => 'success',
    ]);

    Event::assertDispatched(PaymentConfirmed::class);
}

public function test_paypal_amount_mismatch_rejects_capture(): void
{
    $user = User::factory()->create();

    $order = Order::create([
        'order_id' => 'ORD-PAYPAL-MISMATCH-' . uniqid(),
        'tenant_id' => 1,
        'user_id' => $user->user_id,
        'total_amount' => '59.90',
        'taxes' => 0,
        'shipping_cost' => 0,
        'status' => Order::STATUS_PAYMENT_PENDING,
        'items' => [],
        'shipping_address' => [],
        'billing_address' => [],
        'discount' => 0,
    ]);

    Payment::create([
        'order_id' => $order->order_id,
        'payment_id' => 'PAYPAL-ORDER-MISMATCH',
        'gateway' => 'paypal',
        'status' => 'pending',
        'amount' => '59.90',
        'currency' => 'USD',
        'email' => $user->email,
        'user_id' => $user->user_id,
        'tenant_id' => 1,
    ]);

    $incorrectOrder = [
        'id' => 'PAYPAL-ORDER-MISMATCH',
        'status' => 'APPROVED',
        'purchase_units' => [[
            'reference_id' => $order->order_id,
            'amount' => [
                'currency_code' => 'USD',
                'value' => '5.90',
            ],
        ]],
    ];

    $paypal = Mockery::mock(PayPalService::class);

    $paypal->shouldReceive('getOrder')
        ->once()
        ->andReturn($incorrectOrder);

    $paypal->shouldNotReceive('confirmPayment');

    app()->instance(PayPalService::class, $paypal);

    $this->asJwt($user)
        ->postJson('/api/payment/confirm', [
            'gateway' => 'paypal',
            'payment_id' => 'PAYPAL-ORDER-MISMATCH',
        ])
        ->assertStatus(422);

    $this->assertDatabaseHas('payments', [
        'payment_id' => 'PAYPAL-ORDER-MISMATCH',
        'status' => 'pending',
    ]);

    $this->assertDatabaseMissing('transactions', [
        'transaction_id' => 'PAYPAL-ORDER-MISMATCH',
    ]);
}


public function test_paypal_webhook_without_signature_is_rejected(): void
{
    Http::preventStrayRequests();
    Http::fake();

    // Simular el servicio PayPal sin credenciales reales.
    $paypal = Mockery::mock(PayPalService::class);

    $paypal->shouldReceive('verifyWebhookRequest')
        ->once()
        ->with(Mockery::type(\Illuminate\Http\Request::class))
        ->andReturn(false);

    $paypal->shouldNotReceive('getOrder');
    $paypal->shouldNotReceive('confirmPayment');

    app()->instance(PayPalService::class, $paypal);

    $this->postJson('/api/payment/webhook/paypal', [
        'id' => 'WH-FAKE-EVENT',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => 'CAPTURE-FAKE',
            'status' => 'COMPLETED',
            'amount' => [
                'currency_code' => 'USD',
                'value' => '59.90',
            ],
            'supplementary_data' => [
                'related_ids' => [
                    'order_id' => 'PAYPAL-FAKE-ORDER',
                ],
            ],
        ],
    ])
        ->assertStatus(401)
        ->assertJsonPath('error', 'Invalid signature');

    Http::assertNothingSent();

    $this->assertDatabaseCount('transactions', 0);
}

public function test_valid_paypal_webhook_completes_payment(): void
{
    Event::fake([PaymentConfirmed::class]);

    $user = User::factory()->create();

    $order = Order::create([
        'order_id' => 'ORD-WEBHOOK-' . uniqid(),
        'tenant_id' => 1,
        'user_id' => $user->user_id,
        'total_amount' => '59.90',
        'taxes' => 0,
        'shipping_cost' => 0,
        'status' => Order::STATUS_PAYMENT_PENDING,
        'items' => [],
        'shipping_address' => [],
        'billing_address' => [],
        'discount' => 0,
    ]);

    Payment::create([
        'order_id' => $order->order_id,
        'payment_id' => 'PAYPAL-ORDER-WEBHOOK',
        'gateway' => 'paypal',
        'status' => 'pending',
        'amount' => '59.90',
        'currency' => 'USD',
        'email' => $user->email,
        'user_id' => $user->user_id,
        'tenant_id' => 1,
    ]);

    $paypalOrder = [
        'id' => 'PAYPAL-ORDER-WEBHOOK',
        'status' => 'COMPLETED',
        'purchase_units' => [[
            'reference_id' => $order->order_id,
            'amount' => [
                'currency_code' => 'USD',
                'value' => '59.90',
            ],
            'payments' => [
                'captures' => [[
                    'id' => 'PAYPAL-CAPTURE-WEBHOOK',
                    'status' => 'COMPLETED',
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => '59.90',
                    ],
                ]],
            ],
        ]],
    ];

    $paypal = Mockery::mock(PayPalService::class);

    // La verificacion de firma se simula en esta prueba.
    $paypal->shouldReceive('verifyWebhookRequest')
        ->once()
        ->andReturn(true);

    $paypal->shouldReceive('getOrder')
        ->once()
        ->with('PAYPAL-ORDER-WEBHOOK')
        ->andReturn($paypalOrder);

    $paypal->shouldNotReceive('confirmPayment');

    app()->instance(PayPalService::class, $paypal);

    $this->postJson('/api/payment/webhook/paypal', [
        'id' => 'WH-CAPTURE-VALID',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => 'PAYPAL-CAPTURE-WEBHOOK',
            'status' => 'COMPLETED',
            'amount' => [
                'currency_code' => 'USD',
                'value' => '59.90',
            ],
            'supplementary_data' => [
                'related_ids' => [
                    'order_id' => 'PAYPAL-ORDER-WEBHOOK',
                ],
            ],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('payments', [
        'payment_id' => 'PAYPAL-ORDER-WEBHOOK',
        'status' => 'completed',
    ]);

    $this->assertDatabaseHas('transactions', [
        'transaction_id' => 'PAYPAL-CAPTURE-WEBHOOK',
        'payment_method' => 'paypal',
        'status' => 'success',
    ]);

    Event::assertDispatched(PaymentConfirmed::class);
}

public function test_paypal_conversion_preserves_original_amount(): void
{
    $user = User::factory()->create();

    $order = Order::create([
        'order_id' => 'ORD-FX-' . uniqid(),
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

    config()->set('payment.paypal_fx', [
        'pen_per_usd' => '3.50000000',
        'source' => 'Proveedor de prueba',
        'quoted_at' => now()->toIso8601String(),
        'max_age_minutes' => 1440,
    ]);

    $controller = app(\App\Http\Controllers\PaymentController::class);

    $method = new \ReflectionMethod(
        $controller,
        'preparePayPalConversion'
    );

    $result = $method->invoke($controller, $order);

    $this->assertSame('28.57', $result['amount']);
    $this->assertSame('USD', $result['currency']);
    $this->assertSame('100.00', $result['original_amount']);
    $this->assertSame('PEN', $result['original_currency']);
    $this->assertSame('3.50000000', $result['exchange_rate']);

    $this->assertSame(
        '100.00',
        $order->fresh()->getRawOriginal('total_amount')
    );
}
}