<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Transaction;
use App\Services\IzipayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Order $order;
    protected Transaction $transaction;
    protected IzipayService $izipayService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->order = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'checkout'
        ]);
        $this->transaction = Transaction::factory()->create([
            'order_id' => $this->order->id,
            'status' => 'pending'
        ]);
        $this->izipayService = new IzipayService();
    }

    /**
     * Test que webhook rejecta sin HTTPS en producción
     */
    public function test_webhook_rejects_non_https_in_production(): void
    {
        // Skip si no estamos en producción
        if (app()->environment() !== 'production') {
            $this->markTestSkipped('Only tests HTTPS validation in production');
        }

        $response = $this->post('/api/webhooks/izipay', [
            'kr_answer' => 'test',
        ]);

        $this->assertEquals(403, $response->status());
    }

    /**
     * Test que webhook rechaza payload inválido
     */
    public function test_webhook_rejects_invalid_payload(): void
    {
        $response = $this->postJson('/api/webhooks/izipay', []);

        $this->assertIn($response->status(), [400, 422]);
    }

    /**
     * Test que webhook valida firma de Izipay
     */
    public function test_webhook_validates_izipay_signature(): void
    {
        $payload = [
            'kr_answer' => json_encode([
                'transactions' => [
                    [
                        'id' => 'trans_123',
                        'status' => 'AUTHORISED',
                        'amount' => 10000,
                        'currency' => 'PEN',
                        'orderRef' => (string)$this->order->id,
                    ]
                ]
            ]),
            'kr_hash' => 'invalid_hash',
        ];

        $response = $this->postJson('/api/webhooks/izipay', $payload);

        // Debe rechazar firma inválida (401 Unauthorized)
        $this->assertEquals(401, $response->status());
    }

    /**
     * Test que webhook procesa payload de Izipay válido
     */
    public function test_webhook_processes_valid_izipay_payload(): void
    {
        $answer = json_encode([
            'transactions' => [
                [
                    'id' => 'trans_123',
                    'status' => 'AUTHORISED',
                    'amount' => 10000,
                    'currency' => 'PEN',
                    'orderRef' => (string)$this->order->id,
                ]
            ]
        ]);

        // Generar firma válida
        $hash = hash('sha256', $answer);

        $payload = [
            'kr_answer' => $answer,
            'kr_hash' => $hash,
        ];

        $response = $this->postJson('/api/webhooks/izipay', $payload);

        // Debe aceptar y procesar
        $this->assertIn($response->status(), [200, 201]);
    }

    /**
     * Test que webhook previene replay attacks
     */
    public function test_webhook_prevents_replay_attacks(): void
    {
        $payload = [
            'kr_answer' => json_encode([
                'transactions' => [
                    [
                        'id' => 'trans_123',
                        'status' => 'AUTHORISED',
                        'amount' => 10000,
                        'currency' => 'PEN',
                        'orderRef' => (string)$this->order->id,
                    ]
                ]
            ]),
            'kr_hash' => hash('sha256', json_encode([
                'transactions' => [
                    [
                        'id' => 'trans_123',
                        'status' => 'AUTHORISED',
                        'amount' => 10000,
                        'currency' => 'PEN',
                        'orderRef' => (string)$this->order->id,
                    ]
                ]
            ])),
        ];

        // Primera solicitud debe ser válida
        $response1 = $this->postJson('/api/webhooks/izipay', $payload);
        
        // Segunda solicitud idéntica debe ser rechazada
        $response2 = $this->postJson('/api/webhooks/izipay', $payload);

        $this->assertIn($response1->status(), [200, 201]);
        $this->assertEquals(409, $response2->status()); // Conflict - replay attack
    }

    /**
     * Test rate limiting en webhooks
     */
    public function test_webhook_rate_limiting(): void
    {
        $clientIp = '127.0.0.1';

        // Hacer múltiples solicitudes
        for ($i = 0; $i < 15; $i++) {
            $response = $this->withHeaders(['X-Forwarded-For' => $clientIp])
                ->postJson('/api/webhooks/izipay', [
                    'kr_answer' => 'test_' . $i,
                    'kr_hash' => 'test_' . $i,
                ]);
        }

        // Última solicitud debería ser rate limited
        $response = $this->withHeaders(['X-Forwarded-For' => $clientIp])
            ->postJson('/api/webhooks/izipay', [
                'kr_answer' => 'test',
                'kr_hash' => 'test',
            ]);

        $this->assertEquals(429, $response->status()); // Too many requests
    }

    /**
     * Test que webhook actualiza el estado del Order correctamente
     */
    public function test_webhook_updates_order_status(): void
    {
        $answer = json_encode([
            'transactions' => [
                [
                    'id' => 'trans_123',
                    'status' => 'AUTHORISED',
                    'amount' => $this->order->amount * 100,
                    'currency' => 'PEN',
                    'orderRef' => (string)$this->order->id,
                ]
            ]
        ]);

        $payload = [
            'kr_answer' => $answer,
            'kr_hash' => hash('sha256', $answer),
        ];

        $this->postJson('/api/webhooks/izipay', $payload);

        $this->order->refresh();
        
        // El estado debe cambiar a completed
        $this->assertEquals('completed', $this->order->status);
    }

    /**
     * Test que webhook maneja errores de gateway gracefully
     */
    public function test_webhook_handles_gateway_errors(): void
    {
        $payload = [
            'kr_answer' => json_encode([
                'transactions' => [
                    [
                        'id' => 'trans_123',
                        'status' => 'FAILED',
                        'errorCode' => 'ERR_001',
                        'errorMessage' => 'Payment failed',
                        'orderRef' => (string)$this->order->id,
                    ]
                ]
            ]),
            'kr_hash' => 'invalid',
        ];

        $response = $this->postJson('/api/webhooks/izipay', $payload);

        // Debe manejar el error
        $this->assertIn($response->status(), [400, 401, 422]);
    }

    /**
     * Test que webhook registra eventos correctamente
     */
    public function test_webhook_logs_events(): void
    {
        $answer = json_encode([
            'transactions' => [
                [
                    'id' => 'trans_123',
                    'status' => 'AUTHORISED',
                    'amount' => $this->order->amount * 100,
                    'currency' => 'PEN',
                    'orderRef' => (string)$this->order->id,
                ]
            ]
        ]);

        $payload = [
            'kr_answer' => $answer,
            'kr_hash' => hash('sha256', $answer),
        ];

        $this->postJson('/api/webhooks/izipay', $payload);

        // Verificar que se registró en payment_logs
        $this->assertDatabaseHas('payment_logs', [
            'order_id' => $this->order->id,
        ]);
    }
}
