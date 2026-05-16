<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Payment;
use App\Models\Plan;

class PaymentConfirmed extends Mailable
{
    use Queueable, SerializesModels;

    // En lugar de public $payment;
    public Payment $payment;

// En lugar de public $plan;
public Plan $plan;

    /**
     * @param Payment $payment
     * @param Plan $plan
     */
    public function __construct(Payment $payment, Plan $plan)
    {
        $this->payment = $payment;
        $this->plan = $plan;
    }

    public function build()
    {
        return $this->view('emails.payment_confirmed')
                    ->subject('Payment Confirmed - Thank you!');
    }
}