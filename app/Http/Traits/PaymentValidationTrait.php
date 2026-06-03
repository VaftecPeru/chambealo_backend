<?php

namespace App\Http\Traits;

use Illuminate\Support\Facades\Log;

/**
 * PaymentValidationTrait
 * VAFTEC: Validación de Montos desde Backend
 * Proporciona métodos para validar datos de pagos en el backend
 */
trait PaymentValidationTrait
{
    /**
     * Validar monto de pago (VAFTEC)
     * No confiamos en los montos del frontend
     * 
     * @param float|int $amount Monto a validar
     * @param float $minAmount Monto mínimo permitido
     * @param float $maxAmount Monto máximo permitido
     * @return float Monto validado
     * @throws \InvalidArgumentException
     */
    protected function validatePaymentAmount(float|int $amount, float $minAmount = 0.01, float $maxAmount = 999999.99): float
    {
        $amount = (float)$amount;

        if ($amount < $minAmount) {
            throw new \InvalidArgumentException("Monto menor al mínimo permitido: {$minAmount}");
        }

        if ($amount > $maxAmount) {
            throw new \InvalidArgumentException("Monto mayor al máximo permitido: {$maxAmount}");
        }

        Log::info('Monto validado en backend (VAFTEC)', [
            'amount' => $amount,
            'user_id' => auth()->id() ?? 'guest',
            'timestamp' => now(),
        ]);

        return $amount;
    }

    /**
     * Validar moneda
     * 
     * @param string $currency Código de moneda (USD, PEN, etc)
     * @return string Moneda validada
     * @throws \InvalidArgumentException
     */
    protected function validateCurrency(string $currency): string
    {
        $allowedCurrencies = ['USD', 'PEN', 'MXN', 'ARS', 'CLP', 'EUR'];
        $currency = strtoupper($currency);

        if (!in_array($currency, $allowedCurrencies)) {
            throw new \InvalidArgumentException("Moneda no permitida: {$currency}");
        }

        return $currency;
    }

    /**
     * Validar email
     * 
     * @param string $email Email a validar
     * @return string Email validado
     * @throws \InvalidArgumentException
     */
    protected function validateEmail(string $email): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Email inválido: {$email}");
        }

        return strtolower($email);
    }

    /**
     * Validar que la orden existe y pertenece al usuario
     * 
     * @param int $orderId ID de orden
     * @param int|null $userId ID del usuario (null = usar auth())
     * @return \App\Models\Order Orden validada
     * @throws \Exception
     */
    protected function validateOrderBelongsToUser(int $orderId, ?int $userId = null): \App\Models\Order
    {
        $userId = $userId ?? auth()->id();

        if (!$userId) {
            throw new \Exception('Usuario no autenticado');
        }

        $order = \App\Models\Order::where('id', $orderId)
            ->where('user_id', $userId)
            ->firstOrFail();

        return $order;
    }

    /**
     * Validar monto de orden coincide con monto de pago
     * Previene manipulación de montos desde el frontend
     * 
     * @param float $paymentAmount Monto del pago
     * @param float $orderAmount Monto de la orden
     * @param float $tolerance Tolerancia de diferencia (ej: 0.01)
     * @return bool
     * @throws \Exception
     */
    protected function validateAmountMatches(float $paymentAmount, float $orderAmount, float $tolerance = 0.01): bool
    {
        $diff = abs($paymentAmount - $orderAmount);

        if ($diff > $tolerance) {
            Log::warning('Monto de pago no coincide con orden', [
                'payment_amount' => $paymentAmount,
                'order_amount' => $orderAmount,
                'diff' => $diff,
                'user_id' => auth()->id(),
            ]);
            throw new \Exception('El monto del pago no coincide con la orden');
        }

        return true;
    }

    /**
     * Validar que el pago no haya sido procesado ya
     * 
     * @param int $orderId ID de orden
     * @return bool True si es seguro procesar
     * @throws \Exception
     */
    protected function validatePaymentNotProcessed(int $orderId): bool
    {
        $payment = \App\Models\Payment::where('order_id', $orderId)
            ->whereIn('status', ['completed', 'paid', 'approved'])
            ->first();

        if ($payment) {
            Log::warning('Intento de procesar pago ya procesado', [
                'order_id' => $orderId,
                'payment_id' => $payment->id,
                'status' => $payment->status,
            ]);
            throw new \Exception('El pago de esta orden ya ha sido procesado');
        }

        return true;
    }

    /**
     * Validar que se puede procesar refund
     * 
     * @param string $transactionId ID de transacción
     * @param string $gateway Gateway usado
     * @return \App\Models\Payment Pago a reembolsar
     * @throws \Exception
     */
    protected function validateRefundEligibility(string $transactionId, string $gateway): \App\Models\Payment
    {
        $payment = \App\Models\Payment::where('payment_id', $transactionId)
            ->where('gateway', $gateway)
            ->firstOrFail();

        if (!in_array($payment->status, ['completed', 'paid', 'approved'])) {
            throw new \Exception("No se puede reembolsar un pago con estado: {$payment->status}");
        }

        return $payment;
    }

    /**
     * Validar refund parcial es válido
     * 
     * @param float $refundAmount Monto a reembolsar
     * @param float $originalAmount Monto original
     * @return bool
     * @throws \Exception
     */
    protected function validateRefundAmount(float $refundAmount, float $originalAmount): bool
    {
        if ($refundAmount <= 0 || $refundAmount > $originalAmount) {
            throw new \Exception('Monto de refund inválido');
        }

        return true;
    }

    /**
     * Registrar validación exitosa
     * 
     * @param array $data Datos validados
     * @return void
     */
    protected function logValidationSuccess(array $data): void
    {
        Log::info('Payment validation passed', array_merge([
            'user_id' => auth()->id(),
            'timestamp' => now(),
        ], $data));
    }
}
