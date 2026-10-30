<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Transaction;
use App\Repositories\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PaymentRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): PaymentRepository
    {
        return app(PaymentRepository::class);
    }

    private function createPayment(
        string $orderId,
        string $paypalOrderId
    ): Payment {
        return Payment::create([
            'order_id' => $orderId,
            'payment_id' => $paypalOrderId,
            'gateway' => 'paypal',
            'email' => 'comprador@example.test',
            'amount' => '59.90',
            'currency' => 'USD',
            'status' => 'pending',
        ]);
    }

    private function capturedResult(string $captureId): array
    {
        return [
            'id' => 'PAYPAL-ORDER-1',
            'status' => 'completed',
            'transaction_id' => $captureId,
            'amount' => '59.90',
            'currency' => 'USD',
        ];
    }

    private function reservationData(string $orderId): array
    {
        return [
            'order_id' => $orderId,
            'gateway' => 'paypal',
            'email' => 'comprador@example.test',
            'amount' => '28.57',
            'currency' => 'USD',
            'original_amount' => '100.00',
            'original_currency' => 'PEN',
            'exchange_rate' => '3.50000000',
            'exchange_rate_source' => 'Proveedor de prueba',
            'exchange_rate_at' => now(),
        ];
    }

    private function paypalOrderResult(string $orderId): array
    {
        return [
            'id' => $orderId,
            'approve_url' =>
                'https://www.sandbox.paypal.com/checkoutnow?token=TEST',
        ];
    }

    // ============================================================
    // CONFIRMACION Y REGISTRO DE TRANSACCIONES
    // ============================================================

    public function test_completed_payment_creates_one_transaction(): void
    {
        $payment = $this->createPayment(
            'ORDER-1',
            'PAYPAL-ORDER-1'
        );

        $updated = $this->repository()->updatePaymentStatus(
            $payment->payment_id,
            'completed',
            $this->capturedResult('CAPTURE-1')
        );

        $this->assertSame('completed', $updated->status);

        $this->assertDatabaseHas('transactions', [
            'payment_id' => $payment->id,
            'transaction_id' => 'CAPTURE-1',
            'payment_method' => 'paypal',
            'process' => 'confirm_payment',
            'status' => 'success',
        ]);

        $this->assertSame(1, Transaction::count());
    }

    public function test_repeated_confirmation_does_not_duplicate_transaction(): void
    {
        $payment = $this->createPayment(
            'ORDER-1',
            'PAYPAL-ORDER-1'
        );

        $repository = $this->repository();
        $result = $this->capturedResult('CAPTURE-1');

        $repository->updatePaymentStatus(
            $payment->payment_id,
            'completed',
            $result
        );

        $repository->updatePaymentStatus(
            $payment->payment_id,
            'completed',
            $result
        );

        $this->assertSame(1, Transaction::count());
    }

    public function test_approved_payment_is_not_successful(): void
    {
        $payment = $this->createPayment(
            'ORDER-1',
            'PAYPAL-ORDER-1'
        );

        $updated = $this->repository()->updatePaymentStatus(
            $payment->payment_id,
            'approved',
            ['status' => 'APPROVED']
        );

        $this->assertSame('approved', $updated->status);
        $this->assertSame(0, Transaction::count());
    }

    public function test_capture_id_cannot_belong_to_another_payment(): void
    {
        $first = $this->createPayment(
            'ORDER-1',
            'PAYPAL-ORDER-1'
        );

        $second = $this->createPayment(
            'ORDER-2',
            'PAYPAL-ORDER-2'
        );

        $repository = $this->repository();

        $repository->updatePaymentStatus(
            $first->payment_id,
            'completed',
            $this->capturedResult('CAPTURE-1')
        );

        $this->expectException(RuntimeException::class);

        $repository->updatePaymentStatus(
            $second->payment_id,
            'completed',
            $this->capturedResult('CAPTURE-1')
        );
    }

    // ============================================================
    // PROTECCION CONTRA SOBRESCRITURA
    // ============================================================

    public function test_completed_payment_cannot_be_overwritten(): void
    {
        $payment = $this->createPayment(
            'ORDER-1',
            'PAYPAL-ORDER-1'
        );

        $payment->update([
            'status' => 'completed',
        ]);

        $this->expectException(RuntimeException::class);

        $this->repository()->upsertPaymentForOrder([
            'order_id' => 'ORDER-1',
            'payment_id' => 'PAYPAL-ORDER-NEW',
            'gateway' => 'paypal',
            'status' => 'pending',
            'amount' => '59.90',
            'currency' => 'USD',
            'email' => 'comprador@example.test',
        ]);
    }

    public function test_pending_payment_cannot_be_overwritten(): void
    {
        $this->createPayment(
            'ORDER-1',
            'PAYPAL-ORDER-1'
        );

        $this->expectException(RuntimeException::class);

        $this->repository()->upsertPaymentForOrder([
            'order_id' => 'ORDER-1',
            'payment_id' => 'PAYPAL-ORDER-NEW',
            'gateway' => 'paypal',
            'status' => 'pending',
            'amount' => '59.90',
            'currency' => 'USD',
            'email' => 'comprador@example.test',
        ]);
    }

    // ============================================================
    // CONVERSION MONETARIA PEN -> USD
    // ============================================================

    public function test_paypal_payment_preserves_conversion_details(): void
    {
        $payment = Payment::create([
            ...$this->reservationData('ORDER-FX-1'),
            'payment_id' => 'PAYPAL-FX-1',
            'status' => 'pending',
        ]);

        $saved = $payment->fresh();

        $this->assertSame('28.57', $saved->amount);
        $this->assertSame('USD', $saved->currency);
        $this->assertSame('100.00', $saved->original_amount);
        $this->assertSame('PEN', $saved->original_currency);
        $this->assertSame('3.50000000', $saved->exchange_rate);

        $this->assertSame(
            'Proveedor de prueba',
            $saved->exchange_rate_source
        );

        $this->assertNotNull($saved->exchange_rate_at);
    }

    // ============================================================
    // RESERVA DE SESIONES PAYPAL
    // ============================================================

    public function test_paypal_session_can_be_reserved(): void
    {
        $payment = $this->repository()->reservePayPalSession(
            $this->reservationData('ORDER-RESERVE-1')
        );

        $this->assertSame('creating', $payment->status);
        $this->assertNull($payment->payment_id);
        $this->assertSame('28.57', $payment->amount);
        $this->assertSame('100.00', $payment->original_amount);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_duplicate_paypal_reservation_is_rejected(): void
    {
        $repository = $this->repository();

        $data = $this->reservationData('ORDER-RESERVE-2');

        $repository->reservePayPalSession($data);

        $this->expectException(RuntimeException::class);

        $repository->reservePayPalSession($data);
    }

    public function test_creating_paypal_reservation_cannot_be_overwritten(): void
    {
        $repository = $this->repository();

        $data = $this->reservationData('ORDER-CREATING-1');

        $repository->reservePayPalSession($data);

        try {
            $repository->upsertPaymentForOrder([
                ...$data,
                'payment_id' => 'PAYPAL-NEW-ORDER',
                'status' => 'pending',
            ]);

            $this->fail(
                'La reserva creating fue sobrescrita.'
            );
        } catch (RuntimeException $e) {
            $this->assertDatabaseHas('payments', [
                'order_id' => 'ORDER-CREATING-1',
                'status' => 'creating',
                'payment_id' => null,
            ]);

            $this->assertDatabaseCount('payments', 1);
        }
    }

    // ============================================================
    // FINALIZACION DE RESERVAS
    // ============================================================

    public function test_paypal_reservation_can_be_finalized(): void
    {
        $repository = $this->repository();

        $payment = $repository->reservePayPalSession(
            $this->reservationData('ORDER-FINALIZE-1')
        );

        $updated = $repository->finalizePayPalSession(
            $payment->id,
            $this->paypalOrderResult('PAYPAL-ORDER-FINALIZED')
        );

        $this->assertSame('pending', $updated->status);

        $this->assertSame(
            'PAYPAL-ORDER-FINALIZED',
            $updated->payment_id
        );

        $this->assertSame(
            '28.57',
            $updated->fresh()->amount
        );

        $this->assertSame(
            '100.00',
            $updated->fresh()->original_amount
        );

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_paypal_reservation_cannot_be_finalized_twice(): void
    {
        $repository = $this->repository();

        $payment = $repository->reservePayPalSession(
            $this->reservationData('ORDER-FINALIZE-2')
        );

        $result = $this->paypalOrderResult(
            'PAYPAL-ORDER-ONCE'
        );

        $repository->finalizePayPalSession(
            $payment->id,
            $result
        );

        $this->expectException(RuntimeException::class);

        $repository->finalizePayPalSession(
            $payment->id,
            $result
        );
    }

    // ============================================================
    // RESERVAS CON RESULTADO INCIERTO
    // ============================================================

    public function test_uncertain_paypal_reservation_is_preserved(): void
    {
        $repository = $this->repository();

        $data = $this->reservationData(
            'ORDER-UNCERTAIN-1'
        );

        $payment = $repository->reservePayPalSession(
            $data
        );

        $updated = $repository->markPayPalSessionUncertain(
            $payment->id,
            'No se obtuvo respuesta de PayPal'
        );

        $this->assertSame(
            'creation_uncertain',
            $updated->status
        );

        $this->assertNull($updated->payment_id);

        $this->assertDatabaseCount('payments', 1);

        $this->expectException(RuntimeException::class);

        $repository->reservePayPalSession($data);
    }
}
