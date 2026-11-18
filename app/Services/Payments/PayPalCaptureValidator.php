<?php

namespace App\Services\Payments;

use App\Models\Payment;
use InvalidArgumentException;

class PayPalCaptureValidator
{
    public function validate(
        Payment $payment,
        array $paypalOrder,
        ?string $expectedCaptureId = null
    ): array {
        if (($paypalOrder['id'] ?? null) !== $payment->payment_id) {
            throw new InvalidArgumentException(
                'La orden de PayPal no coincide.'
            );
        }

        if (($paypalOrder['status'] ?? null) !== 'COMPLETED') {
            throw new InvalidArgumentException(
                'La orden de PayPal no esta completada.'
            );
        }

        $units = $paypalOrder['purchase_units'] ?? [];

        if (!is_array($units) || count($units) !== 1) {
            throw new InvalidArgumentException(
                'La orden debe contener una unidad de compra.'
            );
        }

        $unit = $units[0];

        if (($unit['reference_id'] ?? null) !== (string) $payment->order_id) {
            throw new InvalidArgumentException(
                'La referencia del pedido no coincide.'
            );
        }

        $captures = data_get($unit, 'payments.captures', []);

        if (!is_array($captures) || count($captures) !== 1) {
            throw new InvalidArgumentException(
                'La orden debe contener una captura.'
            );
        }

        $capture = $captures[0];
        $captureId = $capture['id'] ?? null;

        if (!is_string($captureId) || $captureId === '') {
            throw new InvalidArgumentException(
                'Identificador de captura invalido.'
            );
        }

        if ($expectedCaptureId !== null
            && $captureId !== $expectedCaptureId) {
            throw new InvalidArgumentException(
                'La captura no coincide con el webhook.'
            );
        }

        if (($capture['status'] ?? null) !== 'COMPLETED') {
            throw new InvalidArgumentException(
                'La captura no esta completada.'
            );
        }

        $amount = $capture['amount'] ?? [];

        $expectedAmount = number_format(
            (float) $payment->amount,
            2,
            '.',
            ''
        );

        if (($amount['currency_code'] ?? null) !== $payment->currency
            || ($amount['value'] ?? null) !== $expectedAmount) {
            throw new InvalidArgumentException(
                'El importe o la moneda no coincide.'
            );
        }

        // Verificar que el importe de la unidad corresponda
        // tambien con lo capturado.
        $unitAmount = $unit['amount'] ?? [];

        if (($unitAmount['currency_code'] ?? null) !== $payment->currency
            || ($unitAmount['value'] ?? null) !== $expectedAmount) {
            throw new InvalidArgumentException(
                'El importe original de PayPal no coincide.'
            );
        }

        return [
            'status' => 'completed',
            'transaction_id' => $captureId,
            'order_id' => $paypalOrder['id'],
            'amount' => $expectedAmount,
            'currency' => $payment->currency,
        ];
    }
}
