<?php

namespace App\Listeners;

use App\Events\PaymentConfirmed;
use App\Mail\PaymentConfirmationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class GenerateInvoiceAndSendEmail
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(PaymentConfirmed $event): void
    {
        try {
            $payment = $event->payment;
            $order = $payment->order;
            $user = $order->user;

            // Mark order as paid
            $order->markAsPaid();

            // Send payment confirmation email
            if ($user && $user->email) {
                Mail::to($user->email)->queue(new PaymentConfirmationMail($order, $payment));
                Log::info('Payment confirmation email queued', [
                    'payment_id' => $payment->id,
                    'user_email' => $user->email,
                ]);
            }

            Log::info('PaymentConfirmed event handled successfully', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Error handling PaymentConfirmed event', [
                'error' => $e->getMessage(),
                'payment_id' => $event->payment->id,
            ]);
        }
    }
}
