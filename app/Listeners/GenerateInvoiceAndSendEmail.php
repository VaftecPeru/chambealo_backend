<?php

namespace App\Listeners;

use App\Events\PaymentConfirmed;
use App\Mail\PaymentConfirmationMail;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GenerateInvoiceAndSendEmail
{
    public function __construct()
    {
        //
    }

    public function handle(PaymentConfirmed $event): void
    {
        $payment = $event->payment;

        // Solo procesar pagos realmente completados.
        if (!in_array($payment->status, ['completed', 'paid'], true)) {
            Log::warning('Pago no completado: confirmacion omitida', [
                'payment_id' => $payment->id,
                'status' => $payment->status,
            ]);

            return;
        }

        $order = $payment->order;

        if (!$order) {
            Log::error('No se encontro el pedido del pago', [
                'payment_id' => $payment->id,
            ]);

            throw new \RuntimeException('No se encontro el pedido del pago.');
        }

        // Actualizar solamente pedidos pendientes de pago.
        // Evita confirmar dos veces o retroceder estados.
        $updated = Order::whereKey($order->getKey())
            ->whereIn('status', [
                Order::STATUS_CHECKOUT,
                Order::STATUS_PAYMENT_PENDING,
            ])
            ->update([
                'status' => Order::STATUS_PAID,
            ]);

        if ($updated !== 1) {
            $currentStatus = $order->fresh()?->status;

            // Una notificación repetida no debe volver a enviar correo.
            if (in_array($currentStatus, [
                Order::STATUS_PAID,
                Order::STATUS_SHIPPED,
                Order::STATUS_DELIVERED,
            ], true)) {
                return;
            }

            // No marcar el pago como completado si el pedido está cancelado.
            throw new \RuntimeException(
                'El pedido no admite confirmacion de pago.'
            );
        }

        // Enviar correo una sola vez por transicion a pagado.
        $user = $order->user;

        if ($user && $user->email) {
            Mail::to($user->email)->queue(
                (new PaymentConfirmationMail(
                    $order->fresh(),
                    $payment
                ))->afterCommit()
            );

            Log::info('Correo de confirmacion encolado', [
                'payment_id' => $payment->id,
                'order_id' => $order->order_id,
            ]);
        }

        Log::info('Pedido marcado como pagado correctamente', [
            'payment_id' => $payment->id,
            'order_id' => $order->order_id,
        ]);
    }
}
