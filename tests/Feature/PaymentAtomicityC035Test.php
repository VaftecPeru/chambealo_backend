<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class PaymentAtomicityC035Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake(); // Ningun correo real durante estas pruebas.
    }

    private function createPendingOrderAndPayment(string $status = Order::STATUS_PAYMENT_PENDING): array
    {
        $user = User::factory()->create();

        $order = Order::create([
            'order_id' => 'ORD-C035-' . uniqid('', true),
            'tenant_id' => 1,
            'user_id' => $user->user_id,
            'total_amount' => '59.90',
            'taxes' => 0,
            'shipping_cost' => 0,
            'status' => $status,
            'items' => [],
            'shipping_address' => [],
            'billing_address' => [],
            'discount' => 0,
        ]);

        $payment = Payment::create([
            'order_id' => $order->order_id,
            'payment_id' => 'PAYPAL-C035-' . uniqid('', true),
            'gateway' => 'paypal',
            'status' => 'pending',
            'amount' => '59.90',
            'currency' => 'USD',
            'email' => $user->email,
            'user_id' => $user->user_id,
            'tenant_id' => 1,
        ]);

        return [$order, $payment];
    }

    private function persistCompletedPayment(Payment $payment, string $captureId): void
    {
        // Invocar directamente el nuevo metodo de C-035 para comprobar su atomicidad.
        $controller = app(\App\Http\Controllers\PaymentController::class);
        $method = new ReflectionMethod($controller, 'persistPaymentConfirmation');
        $method->invoke($controller, $payment->payment_id, 'completed', [
            'id' => $payment->payment_id,
            'status' => 'COMPLETED',
            'transaction_id' => $captureId,
            'amount' => '59.90',
            'currency' => 'USD',
        ]);
    }

    public function test_completed_payment_updates_order_and_transaction_together(): void
    {
        [$order, $payment] = $this->createPendingOrderAndPayment();

        $this->persistCompletedPayment($payment, 'CAPTURE-C035-1');

        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(1, Transaction::where('payment_id', $payment->id)->count());

        // Evento duplicado: ni otra transaccion ni una regresion de estado.
        $this->persistCompletedPayment($payment, 'CAPTURE-C035-1');
        $this->assertSame(1, Transaction::where('payment_id', $payment->id)->count());
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_cancelled_order_rolls_back_payment_and_transaction(): void
    {
        [$order, $payment] = $this->createPendingOrderAndPayment(Order::STATUS_CANCELLED);

        $caught = false;
        try {
            $this->persistCompletedPayment($payment, 'CAPTURE-C035-CANCELLED');
        } catch (RuntimeException $exception) {
            $caught = true;
        }

        $this->assertTrue($caught, 'La confirmacion de un pedido cancelado debe fallar.');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(0, Transaction::where('payment_id', $payment->id)->count());
    }
}
