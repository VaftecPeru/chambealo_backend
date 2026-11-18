<?php

namespace Tests\Feature;

use App\Services\PayPalService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayPalOrderCreationTest extends TestCase
{
    public function test_create_order_uses_stable_request_id(): void
    {
        config()->set('payment.paypal.environment', 'sandbox');
        config()->set('payment.paypal.client_id', 'CLIENT_TEST');
        config()->set('payment.paypal.client_secret', 'SECRET_TEST');
        config()->set('payment.paypal.webhook_id', '');

        Http::preventStrayRequests();

        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' =>
                Http::response([
                    'access_token' => 'TOKEN_TEST',
                    'token_type' => 'Bearer',
                    'expires_in' => 3600,
                ], 200),

            'https://api-m.sandbox.paypal.com/v2/checkout/orders' =>
                Http::response([
                    'id' => 'PAYPAL-ORDER-TEST',
                    'status' => 'CREATED',
                    'links' => [
                        [
                            'rel' => 'approve',
                            'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=TEST',
                        ],
                    ],
                ], 201),
        ]);

        $service = app(PayPalService::class);

        $data = [
            'reservation_id' => 123,
            'order_id' => 'ORDER-TEST-1',
            'amount' => '28.57',
            'currency' => 'USD',
            'tenant_id' => 1,
            'return_url' => 'https://example.test/pago-exitoso',
            'cancel_url' => 'https://example.test/pago-cancelado',
        ];

        $first = $service->createPayment($data);
        $second = $service->createPayment($data);

        $this->assertSame(
            'PAYPAL-ORDER-TEST',
            $first['id']
        );

        $this->assertSame(
            $first['approve_url'],
            $second['approve_url']
        );

        $expectedRequestId = hash(
            'sha256',
            'chambealo-paypal-create-123'
        );

        Http::assertSentCount(4);

        $creationRequests = Http::recorded(
            fn (Request $request) =>
                $request->method() === 'POST'
                && str_ends_with(
                    $request->url(),
                    '/v2/checkout/orders'
                )
        );

        $this->assertCount(2, $creationRequests);

        foreach ($creationRequests as [$request, $response]) {
            $this->assertSame(
                $expectedRequestId,
                $request->header('PayPal-Request-Id')[0] ?? null
            );

            $this->assertSame(
                'USD',
                $request->data()['purchase_units'][0]['amount']['currency_code']
            );

            $this->assertSame(
                '28.57',
                $request->data()['purchase_units'][0]['amount']['value']
            );

            $this->assertSame(
                'ORDER-TEST-1',
                $request->data()['purchase_units'][0]['reference_id']
            );
        }
    }

    public function test_create_order_rejects_missing_reservation_id(): void
    {
        config()->set('payment.paypal.environment', 'sandbox');
        config()->set('payment.paypal.client_id', 'CLIENT_TEST');
        config()->set('payment.paypal.client_secret', 'SECRET_TEST');
        config()->set('payment.paypal.webhook_id', '');

        Http::preventStrayRequests();
        Http::fake();

        $service = app(PayPalService::class);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $service->createPayment([
                'order_id' => 'ORDER-WITHOUT-RESERVATION',
                'amount' => '28.57',
                'currency' => 'USD',
            ]);
        } finally {
            Http::assertNothingSent();
        }
    }
}
