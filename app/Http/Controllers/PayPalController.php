<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
// Importación exacta de tus modelos según tu imagen
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Plan;
use App\Models\Audit; 
use App\Mail\PaymentConfirmed;

class PayPalController extends Controller
{
    private function getPayPalAccessToken()
    {
        $clientId = config('services.paypal.client_id');
        $clientSecret = config('services.paypal.secret');

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->post('https://api-m.sandbox.paypal.com/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        return $response->json()['access_token'];
    }

    public function createOrder(Request $request)
    {
        $accessToken = $this->getPayPalAccessToken();
        // Validamos que el plan existe antes de ir a PayPal
        $plan = Plan::findOrFail($request->plan_id);

        $response = Http::withToken($accessToken)
            ->post('https://api-m.sandbox.paypal.com/v2/checkout/orders', [
                "intent" => "CAPTURE",
                "purchase_units" => [[
                    "amount" => [
                        "currency_code" => "USD",
                        "value" => number_format($plan->price, 2, '.', '')
                    ]
                ]]
            ]);

        return response()->json($response->json());
    }

    public function captureOrder(Request $request)
    {
        $orderID = $request->orderID;
        $accessToken = $this->getPayPalAccessToken();

        $response = Http::withToken($accessToken)
            ->post("https://api-m.sandbox.paypal.com/v2/checkout/orders/{$orderID}/capture");

        $data = $response->json();

        if (isset($data['status']) && $data['status'] === 'COMPLETED') {
            try {
                DB::transaction(function () use ($data) {
                    $this->saveTransaction($data);
                });
                return response()->json(['message' => 'Pago procesado exitosamente']);
            } catch (\Exception $e) {
                return response()->json(['error' => 'Error en DB: ' . $e->getMessage()], 500);
            }
        }
        return response()->json(['error' => 'No se pudo capturar el pago'], 500);
    }

    private function saveTransaction(array $data)
    {
    /** @var \App\Models\User $user */
    $user = auth()->user();
    
        $amount = $data['purchase_units'][0]['payments']['captures'][0]['amount']['value'];

        // 1. Crear el registro de pago
        $payment = Payment::create([
            'order_id' => $data['id'],
            'email'    => $data['payer']['email_address'],
            'amount'   => $amount,
            'status'   => 'completed',
        ]);

        // 2. Lógica de Plan y Suscripción
        $plan = Plan::where('price', '<=', $amount)->orderBy('price', 'desc')->first();
        
        if ($plan && $user) {
            Subscription::create([
                'user_id'    => $user->user_id,
                'plan_id'    => $plan->plan_id,
                'start_date' => now(),
                'end_date'   => now()->addDays($plan->duration_days),
                'status'     => 'active',
            ]);

            // Actualizar rol del usuario
            $user->update(['role' => 'premium']);

            // 3. Registrar en Auditoría (usando tu modelo Audit.php)
            Audit::create([
                'entity_type'  => 'Payment',
                'entity_id'    => $payment->id,
                'action'       => 'PAYMENT_SUCCESS',
                'performed_by' => $user->user_id,
                'description'  => "Compra de plan completada por {$amount} USD",
                'ip_address'   => request()->ip(),
                'user_agent'   => request()->userAgent()
            ]);
            
            // 4. Enviar Email
            Mail::to($user->email)->send(new PaymentConfirmed($payment, $plan));
        }
    }
}