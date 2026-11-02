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

            return;
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
            Log::info('Pedido ya procesado o estado no permitido', [
                'payment_id' => $payment->id,
                'order_id' => $order->order_id,
            ]);

            return;
        }

        // Enviar correo una sola vez por transicion a pagado.
        $user = $order->user;

        if ($user && $user->email) {
            Mail::to($user->email)->queue(
                new PaymentConfirmationMail(
                    $order->fresh(),
                    $payment
                )
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
