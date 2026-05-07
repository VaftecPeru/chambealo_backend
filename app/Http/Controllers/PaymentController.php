<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
// Estos son los que funcionan con la versión 2.x que acabas de instalar
use MercadoPago\SDK;
use MercadoPago\Payment;

class PaymentController extends Controller
{
    public function process(Request $request)
    {
        // Configura tu token
        SDK::setAccessToken(env('MP_ACCESS_TOKEN'));

        $payment = new Payment();
        // 3. Intentar realizar el cobro
        try {
            $payment = $client->create([
                "token" => $request->token,
                "issuer_id" => $request->issuer_id,
                "payment_method_id" => $request->payment_method_id,
                "transaction_amount" => (float)$request->transaction_amount,
                "installments" => $request->installments,
                "payer" => [
                    "email" => $request->payer['email'],
                ]
            ]);

            return response()->json([
                "status" => $payment->status,
                "message" => $payment->status_detail,
                "id" => $payment->id
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}