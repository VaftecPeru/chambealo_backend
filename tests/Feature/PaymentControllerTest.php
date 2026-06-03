<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->order = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'checkout',
            'amount' => 100,
        ]);
    }

    /**
     * Test que user no autenticado no puede crear sesión de pago
     */
    public function test_unauthenticated_user_cannot_create_payment_session(): void
    {
        $response = $this->postJson('/api/payments/session', [
            'order_id' => $this->order->id,
            'gateway' => 'izipay',
        ]);

        $this->assertEquals(401, $response->status());
    }

    /**
     * Test que user no puede acceder a orden de otro usuario
     */
    public function test_user_cannot_create_session_for_other_user_order(): void
    {
        $otherUser = User::factory()->create();
        $otherOrder = Order::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'checkout',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/payments/session', [
                'order_id' => $otherOrder->id,
                'gateway' => 'izipay',
            ]);

        $this->assertEquals(403, $response->status());
    }

    /**
     * Test que admin puede acceder a cualquier orden
     */
    public function test_admin_can_create_session_for_any_order(): void
    {
        $admin = User::factory()->admin()->create();
        
        $response = $this->actingAs($admin)
            ->postJson('/api/payments/session', [
                'order_id' => $this->order->id,
                'gateway' => 'izipay',
            ]);

        // No debe retornar 403 (Forbidden)
        $this->assertNotEquals(403, $response->status());
    }

    /**
     * Test que user autenticado puede crear sesión de pago
     */
    public function test_authenticated_user_can_create_payment_session(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/payments/session', [
                'order_id' => $this->order->id,
                'gateway' => 'izipay',
            ]);

        $this->assertIn($response->status(), [200, 201]);
        $this->assertArrayHasKey('session_id', $response->json());
    }

    /**
     * Test que no se puede crear sesión para orden inexistente
     */
    public function test_cannot_create_session_for_nonexistent_order(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/payments/session', [
                'order_id' => 99999,
                'gateway' => 'izipay',
            ]);

        $this->assertEquals(404, $response->status());
    }

    /**
     * Test que gateway inválido es rechazado
     */
    public function test_invalid_gateway_is_rejected(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/payments/session', [
                'order_id' => $this->order->id,
                'gateway' => 'invalid_gateway',
            ]);

        $this->assertIn($response->status(), [400, 422]);
    }

    /**
     * Test que confirmación de pago actualiza estado del Order
     */
    public function test_payment_confirmation_updates_order_status(): void
    {
        $transaction = Transaction::factory()->create([
            'order_id' => $this->order->id,
            'status' => 'pending',
            'gateway' => 'izipay',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/payments/confirm', [
                'order_id' => $this->order->id,
                'transaction_id' => $transaction->id,
                'gateway' => 'izipay',
            ]);

        $this->order->refresh();
        
        // El estado debe cambiar a completed o confirmado
        $this->assertIn($this->order->status, ['completed', 'confirmed', 'paid']);
    }

    /**
     * Test que refund crea nueva transacción
     */
    public function test_refund_creates_refund_transaction(): void
    {
        $transaction = Transaction::factory()->create([
            'order_id' => $this->order->id,
            'status' => 'completed',
            'gateway' => 'izipay',
            'amount' => 100,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/payments/refund', [
                'order_id' => $this->order->id,
                'transaction_id' => $transaction->id,
                'amount' => 100,
            ]);

        $this->assertIn($response->status(), [200, 201]);
        
        // Verificar que se creó una transacción de refund
        $this->assertDatabaseHas('transactions', [
            'order_id' => $this->order->id,
            'type' => 'refund',
        ]);
    }

    /**
     * Test que no se puede refundear más del monto de la transacción
     */
    public function test_cannot_refund_more_than_transaction_amount(): void
    {
        $transaction = Transaction::factory()->create([
            'order_id' => $this->order->id,
            'status' => 'completed',
            'gateway' => 'izipay',
            'amount' => 100,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/payments/refund', [
                'order_id' => $this->order->id,
                'transaction_id' => $transaction->id,
                'amount' => 150, // Más que el monto disponible
            ]);

        $this->assertEquals(422, $response->status());
    }

    /**
     * Test health check
     */
    public function test_payment_controller_health_check(): void
    {
        $response = $this->getJson('/api/payments/health');

        $this->assertEquals(200, $response->status());
        $this->assertTrue($response->json('health'));
    }

    /**
     * Test que Payment Controller valida datos requeridos
     */
    public function test_payment_controller_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/payments/session', [
                // Falta order_id y gateway
            ]);

        $this->assertEquals(422, $response->status());
        $this->assertArrayHasKey('errors', $response->json());
    }

    /**
     * Test que se registra el pago en payment_logs
     */
    public function test_payment_is_logged(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/payments/session', [
                'order_id' => $this->order->id,
                'gateway' => 'izipay',
            ]);

        // Verificar que se registró en payment_logs
        $this->assertDatabaseHas('payment_logs', [
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
            'gateway' => 'izipay',
        ]);
    }
}
